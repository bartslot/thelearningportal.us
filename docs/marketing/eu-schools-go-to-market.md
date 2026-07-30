# Getting into European schools: backlinks, awareness, outreach

Written 2026-07-30. Researched, not guessed — sources are linked inline. Nothing here has been sent
to anybody: the outreach drafts at the end are for you to send from your own mailbox.

---

## 0. The gate before any of this: a data-protection pack

This is the finding that reorders everything else. A European school cannot adopt the portal — however
much a teacher likes it — until someone can answer the data-protection questions, because the school
is the controller and we are the processor.

What schools are now required to have in place before adopting a tool:

- a **data processing agreement** with every vendor that touches personal data
  ([GDPR Local](https://gdprlocal.com/gdpr-for-schools/))
- a **DPIA** before deploying anything that profiles pupils or uses AI on their data
  ([Plan Be Eco](https://planbe.eco/en/blog/gdpr-for-the-education-industry/))
- and in the UK specifically, procurement is now framed as a joined-up governance decision covering
  data protection, safeguarding, AI and vendor accountability — the DfE published guidance on this
  on 9 July 2026 ([GOV.UK](https://www.gov.uk/guidance/data-protection-in-schools/procuring-educational-technology-edtech),
  [9ine](https://www.9ine.com/newsblog/dfe-edtech-procurement-guidance-the-department-is-joining-the-dots-for-schools))

We are in scope: quiz scores, class rosters with children's first names, and narration generated
through third-party AI services.

**Build this pack before outreach — a week of work that unblocks every conversation:**

| Document | Why a school asks for it |
|---|---|
| Data processing agreement (template, ready to sign) | Legally required before they can use us |
| Sub-processor list (Azure TTS, ElevenLabs, OpenAI, Supabase, SiteGround — with regions) | First question any DPO asks |
| DPIA support note | They write the DPIA; we make it answerable |
| Retention + deletion statement | "What happens to a child's answers?" |
| A statement that pupils need no account | Genuinely our strongest card — most tools cannot say this |

That last row is a real competitive advantage. Lessons play from a six-character code with no pupil
account, so the pupil personal data we hold is close to nothing. Say it loudly and early.

---

## 1. Backlinks that are actually attainable

Ignore generic link building. In education the links that carry weight come from curriculum and OER
portals, and they are earned by *submitting a genuinely useful resource*, not by asking.

### Tier 1 — submit a real lesson, get a real link

| Target | Country | What it takes | Notes |
|---|---|---|---|
| [KlasCement](https://www.klascement.net/) | Flanders/NL | Free account, then "Verzend je leermiddel" | Moderated against published criteria, days to weeks. Prefers **Creative Commons** and editable formats ([help](https://klascement.info/help/hoe-iets-toevoegen/)). Run by the Flemish education department — high trust. |
| [Wikiwijs](https://www.wikiwijs.nl/) | Netherlands | Account, publish as OER with a CC licence | Kennisnet-run, the default NL place teachers look. LTI hook-up is possible via info@wikiwijs.nl — worth asking about once the pack in §0 exists. |
| [European School Education Platform / eTwinning](https://school-education.ec.europa.eu/en) | EU-wide | Register as school staff | A *community*, not a vendor directory. Value is credibility and reach, and you must participate as a teacher, not advertise. |

Practical move: publish two or three of the strongest lessons (Anne Frank, the VOC, the Watersnood)
under CC BY-SA on KlasCement and Wikiwijs, each linking back to its
`/history-lessons/{slug}` page. That page now exists, is indexable and is translated — which is
exactly what makes the link worth having.

### Tier 2 — directories, with one important correction

**Do not spend time on Common Sense Education.** They paused edtech reviews in February 2026 after
running the programme from 2013–2025, and are not processing new review requests
([Common Sense FAQ](https://www.commonsense.org/education/reviews/FAQ),
[Tech & Learning](https://www.techlearning.com/technology/apps/common-sense-education-will-pause-edtech-reviews-beginning-february-2026-what-it-means-for-schools-and-where-to-look-next)).

The replacement teachers are being pointed to is the **EdTech Index** from ISTE+ASCD
([EdTech Institute](https://edtechinstitute.com/2026/02/11/common-sense-education-reviews-paused-2026-alternatives/)).
Submit there instead.

### Tier 3 — the links nobody else can get

`.edu`/`.ac` links are earned by being useful to a researcher or teacher-educator, not by asking
([Links.me](https://links.me/blog/link-building/edu-backlinks/)). Two openings specific to us:

1. **Teacher-training departments.** The portal is a ready-made object of study for "AI in history
   teaching" seminars. Offer free accounts to a PABO / Lehramt / INSPÉ cohort in exchange for nothing
   but their feedback. Course pages link to tools they use.
2. **Heritage institutions.** Lessons already credit Wikimedia Commons and museum collections. A
   lesson built *with* a regional archive or museum, credited on their site, is a strong local link
   and a story a journalist will actually run.

---

## 2. Brand awareness, in the order that compounds

1. **Own the long tail in five languages first.** This is already live: a lesson page per language,
   hreflang, sitemap, structured data. A Dutch teacher searching "les over de Watersnood" or a German
   one searching "Unterrichtsstunde Anne Frank" is a warmer lead than any ad. Publish more lessons —
   each one is a new indexable page.
2. **Write the three articles only you can write.** The `/articles` bridge now pulls from WordPress,
   so write in WordPress and it appears on the subdomain automatically:
   - How narration and museum imagery get sourced (the anti-hallucination discipline: source text
     first, omit rather than invent). This is a trust document.
   - What a lesson costs a teacher in minutes, measured honestly.
   - Why pupils need no account, and what data therefore never exists.
3. **Show, do not tell.** A 60-second screen recording of a lesson being built beats any copy. The
   help screenshots are already captured per language — the same pipeline can record video.
4. **Go where history teachers already are**, as a teacher: the national subject associations
   (NL: VGN; DE: Verband der Geschichtslehrer; FR: APHG; IT: Clio '92). Their journals and mailing
   lists reach exactly the audience, and they publish member-written pieces.

---

## 3. Outreach: drafts for you to send

I have not contacted anyone and will not — school and platform outreach should come from you, from
your own address, with your name on it. Two drafts, deliberately short and honest about status.

### 3a. To a history teacher (warm, individual)

> Subject: A history lesson on [topic], if it is useful to you
>
> Dear [name],
>
> I build The Learning Portal — narrated history lessons a teacher makes in minutes. I made one on
> [topic] for [age group] and thought of your class: [link to the lesson page].
>
> It plays in a browser from a six-character code. Your pupils need no account and create no login,
> so there is very little of their data anywhere.
>
> We are preparing for wider classroom use rather than claiming to be finished. If you try it, the
> thing I would most like to know is where it does not fit your curriculum.
>
> [name] · history.thelearningportal.us

### 3b. To a school or department lead

> Subject: History lessons in five languages — and the data questions answered up front
>
> Dear [name],
>
> The Learning Portal builds narrated history lessons from a topic and an age group, in English,
> Dutch, German, French and Italian. A teacher gets a finished, editable lesson in minutes.
>
> Because I know this is the first question: pupils need no account. A lesson opens from a code, and
> the only pupil data we hold is quiz answers against a first name and last initial, if a teacher
> chooses to set up a class at all. I have attached our processing agreement, sub-processor list and
> a note to support your DPIA.
>
> We are getting ready for the new school year and I would rather hear what your curriculum needs
> than pitch you. Would a 20-minute call be useful?
>
> [name] · history.thelearningportal.us

**Do not** send either of these as a bulk mail. Cold bulk mail to schools is both ineffective and,
in several EU countries, legally fraught. Ten researched, individual emails will outperform a
thousand-address blast, and will not damage the domain's reputation.

---

## 4. Order of work

1. The data-protection pack (§0). Nothing else converts without it.
2. Publish two or three lessons as CC on KlasCement + Wikiwijs, linking back.
3. Write the three articles in WordPress; they appear on the subdomain automatically.
4. Submit to the ISTE+ASCD EdTech Index.
5. Approach one teacher-training department and one regional archive.
6. Only then, individual outreach to teachers — with a lesson in their subject, not a pitch.

---

## Sources

- [European School Education Platform / eTwinning](https://school-education.ec.europa.eu/en) · [About eTwinning](https://school-education.ec.europa.eu/en/etwinning/about)
- [Wikiwijs](https://www.wikiwijs.nl/) · [Wikiwijs via Kennisnet](https://www.kennisnet.nl/tools/wikiwijs/)
- [KlasCement](https://www.klascement.net/) · [How to add material](https://klascement.info/help/hoe-iets-toevoegen/) · [What you can add](https://klascement.info/help/wat-kan-je-toevoegen-aan-klascement/)
- [Common Sense Education reviews FAQ](https://www.commonsense.org/education/reviews/FAQ) · [Tech & Learning on the pause](https://www.techlearning.com/technology/apps/common-sense-education-will-pause-edtech-reviews-beginning-february-2026-what-it-means-for-schools-and-where-to-look-next) · [EdTech Institute on alternatives](https://edtechinstitute.com/2026/02/11/common-sense-education-reviews-paused-2026-alternatives/)
- [GOV.UK: procuring educational technology](https://www.gov.uk/guidance/data-protection-in-schools/procuring-educational-technology-edtech) · [9ine on the DfE guidance](https://www.9ine.com/newsblog/dfe-edtech-procurement-guidance-the-department-is-joining-the-dots-for-schools)
- [GDPR for schools](https://gdprlocal.com/gdpr-for-schools/) · [GDPR for education 2026](https://planbe.eco/en/blog/gdpr-for-the-education-industry/)
- [EDU backlinks for edtech SEO](https://links.me/blog/link-building/edu-backlinks/)
