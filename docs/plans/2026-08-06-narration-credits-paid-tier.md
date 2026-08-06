# Paid tier: teachers buy narration credits with Stripe

Branch `claude/determined-mirzakhani-6812d9`, based on `polishing-1`. Built and green.

---

## 1. The two decisions, and who made them

### VAT: the 5 euro is INCLUSIVE

Bart answered this twice, directly, in chat. The PM session relayed the opposite ("excluding") in
between; that was raised rather than acted on, and Bart confirmed inclusive. Recording it here
because the two answers net us 4.13 and 5.00 respectively and the difference is not recoverable
after the fact.

So a teacher pays exactly **5.00 euro** wherever they are, and the VAT owed at their own country's
rate comes out of it. Stripe Tax computes the rate (21% NL, 19% DE, 22% IT) and reports the split
back, which is what gets stored. A school entering a valid EU VAT number is reverse-charged and the
whole 5.00 is net.

Everything about this is one line if it ever changes: `tax_behavior` in `StripeCheckout` and
`NARRATION_CREDIT_AMOUNT_CENTS` in `.env`.

### Expiry: 30 days, and the window is configurable

`NARRATION_CREDIT_EXPIRY_DAYS`, default 30. Set it to 0 and nothing expires at all.

**Still worth one sentence with an accountant.** Prepaid credit that expires is regulated in several
EU countries, and 30 days is short by the standards of those rules. A consumer who loses paid-for
credit after a month is the kind of thing that produces a chargeback rather than a support ticket.
Built as specified; the config value means changing it later is an env edit, not a migration.

---

## 2. Ledger schema

`narration_credits`, append-only. A balance is a SUM, never a column anyone writes. The model refuses
`update` and `delete` outright, so that is structural rather than a convention someone remembers.

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `teacher_id` | fk users, cascade | indexed |
| `characters` | **signed** int | + bought, - spent/refunded/charged back |
| `reason` | string(32) | `App\Enums\NarrationCreditReason` |
| `lesson_id` | fk lessons, **nullOnDelete** | which lesson a spend went on |
| `batch_id` | fk self, nullable | which purchase a spend came out of |
| `expires_at` | timestamp, nullable | purchases only; null never expires |
| `amount_net_cents` | uint, nullable | what we keep |
| `amount_vat_cents` | uint, nullable | what the tax office gets |
| `amount_gross_cents` | uint, nullable | what the teacher paid |
| `currency` | char(3), nullable | |
| `stripe_event_id` | string, **unique** | the idempotency key, see section 3 |
| `stripe_payment_intent_id` | string, nullable, indexed | refund to its purchase |
| `created_at` | timestamp | no `updated_at`: rows are never edited |

Three money columns rather than one gross total, because the VAT rate depends on the customer's
country and a gross figure cannot be taken apart again at the end of a quarter.

`lesson_id` deliberately breaks the house `cascadeOnDelete` rule: deleting a lesson must not erase
the record that a teacher paid for it.

### Why the balance is not a plain SUM

A purchase is a **batch** with an `expires_at`. Everything that draws it down names the batch.

```sql
SELECT SUM(c.characters)
FROM narration_credits c
JOIN narration_credits b ON b.id = COALESCE(c.batch_id, c.id)
WHERE c.teacher_id = ? AND (b.expires_at IS NULL OR b.expires_at > now())
```

A purchase is its own batch, a spend points at the one it came from, so one pass and no grouping.

Spending is **FIFO by expiry**, and a never-expiring batch goes last: use up what is about to be
lost, keep what cannot be. Sorted in PHP rather than SQL because Postgres and MySQL disagree about
where NULLs belong in an ORDER BY. An edit crossing a batch boundary writes one row per batch.

**Expired credit is derived, never swept.** No scheduled job writes expiry rows. A cron that quietly
stops running would hand teachers credit they no longer own, and prod `schedule:run` was dead for
two weeks in July. A query cannot drift, and the buy screen still shows what expired and when.

The free 1,000 characters per lesson do not expire. They are not bought, and they live on the lesson.

---

## 3. What happens on a duplicated webhook

Nothing, and Stripe is told **200**.

`stripe_event_id` is unique and holds the Stripe object the row is idempotent on:

- **A purchase stores the Checkout Session id (`cs_...`)**, not the event id. One sale can be
  announced by two different events: iDEAL sends `checkout.session.completed` while still unpaid and
  `checkout.session.async_payment_succeeded` when the bank clears. Two event ids, one grant.
- **A refund or dispute stores the event id (`evt_...`)**, because each of those is a separate thing
  that happened.

The check is not SELECT-then-INSERT, which two simultaneous retries would both pass. The INSERT
itself is the check: the loser gets a duplicate-key error, caught, logged at info, answered 200. An
error response would not get the duplicate looked at, it would get the same duplicate redelivered
with backoff for days.

The insert runs inside its own nested transaction, so the collision rolls back a **savepoint** and
not whatever transaction happened to be open. Postgres aborts an entire transaction on a failed
statement and refuses everything after it, so catching the exception is not on its own enough.

---

## 4. How a teacher cannot spend the same credits twice

The race: two wizard panels autosave at once, both read "500 left", both spend it.

**Deciding and charging are one operation.** `NarrationBudget::charge()` opens a transaction, takes
`lockForUpdate()` on the **teacher's own row** (every charge against any of their lessons queues
behind the same lock), re-reads the lesson inside the lock, and only then decides. It returns
`false` having taken nothing if the teacher cannot afford it.

The lock is on the owner, not the ledger rows, because a balance is a sum: locking the rows that
exist does not stop someone inserting a new one.

The call site changed order to match. It used to check, save, then charge, with the race in the gap.
It now charges first and saves only if the charge succeeded. A save failing after a charge costs a
teacher a few characters; a charge failing after a save costs us an ElevenLabs invoice with nothing
recorded against it.

### The tests

`tests/Feature/Billing/NarrationCreditSpendTest.php` (11) and `StripeWebhookTest.php` (12).

The one that was asked for is `test_a_teacher_cannot_spend_the_same_credits_twice`: grant exactly
100, charge 100 twice, assert the first returns true, the second false, and the ledger lands on 0
and never on -100. Two more back it up, because a sequential test alone cannot prove a lock exists:

- `test_the_charge_locks_the_teacher_before_deciding` reads the query log and asserts a
  `for update` against `users` was actually issued.
- `test_a_charge_reads_the_balance_as_it_is_now_not_as_the_page_loaded_it` holds a stale Lesson,
  spends everything through another path, and asserts the stale charge is refused.

Also covered: FIFO by expiry, expired credit unspendable and undeleted, expiry switched off, the
append-only guard, free-allowance-before-credit, an edit spanning both pots, guests, signature
rejection, missing secret, iDEAL's two events, partial and repeated refunds, chargebacks, and
net/VAT/gross being recorded separately.

---

## 5. Result

- `php vendor/bin/phpunit` — **1159 passed** (1136 before, 23 added). 3 pre-existing deprecations,
  none from the new tests.
- `npx vitest run` — **393 passed**, unchanged.
- `php artisan lang:audit` — **"Every language covers the whole interface."** 32 new strings in
  nl, de, fr, it.

### Two bugs found reviewing my own work, both fixed

1. `StripeClient` is constructible with no arguments, so Laravel's container would have autowired a
   **keyless** client into `StripeCheckout` and every API call would have gone out unauthenticated.
   The client is built from the secret now, never injected.
2. The FIFO sort re-sorted the collection after the SQL had already ordered it, and PHP puts NULL
   first, so a **never-expiring** batch would have been spent before an expiring one.

---

## 6. What Bart has to do

1. **Set the keys yourself.** `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` in `.env`, nowhere else.
   Nothing in this branch contains a key and nothing should. Test mode (`sk_test_...`) until the
   flow has been walked end to end.
2. **Add the webhook endpoint** in Stripe: `https://<host>/stripe/webhook`, subscribed to
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`,
   `charge.dispute.created`. Locally, `stripe listen --forward-to localhost:8000/stripe/webhook`.
3. **Switch Stripe Tax on** in the dashboard and register the OSS details, or set
   `STRIPE_AUTOMATIC_TAX=false` and handle VAT by hand.
4. **Ask an accountant about the 30-day expiry** (section 1).
5. **Run the migration on prod** when this deploys.

I have not tested against Stripe's live or test API: that needs a key, and asking for one is
section 6 item 1. Everything below the API boundary is covered by the 23 tests above, and the
webhook is exercised with genuinely signed payloads.

---

## 7. Base branch

The brief said `main`. `main` is stale at 3248d38 (1 August) and does not contain `NarrationBudget`
at all. The seam landed on **`polishing-1`** (b171aec, today), 59 commits ahead, so this branches
from `polishing-1` and should merge back there.
