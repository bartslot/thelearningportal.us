# Teacher UX Test Session — History Portal

**Date:** 2026-05-28  
**Participant:** [Teacher name]  
**School / Grade level:** ___________________________  
**Facilitator:** Bart Slot  
**Session length:** ~60–90 min

---

## Session Goals

1. Observe a real teacher using the portal with no hand-holding
2. Find friction points in the lesson creation wizard
3. Validate whether the core value proposition ("AI-generated avatar lessons in minutes") lands
4. Capture verbatim reactions for marketing/product decisions

---

## Pre-Session Checklist

- [ ] Laptop charged, browser open to `/login`
- [ ] Test teacher account seeded: `teacher@test.com` / `testing123`
- [ ] Queue worker running (`php artisan queue:work`)
- [ ] At least one pre-generated lesson in the dashboard (fallback if generation is slow)
- [ ] Screen recording running (OBS / QuickTime)
- [ ] Notepad ready for timestamps + quotes
- [ ] Phone / second screen for student view (optional)

---

## Part 1 — Usability Test (observe, don't help)

Give the teacher the URL and credentials. Say:
> "Pretend you just signed up. Your goal is to create a lesson for one of your classes. I'll stay quiet — think out loud as you go."

### Task List

| # | Task | Success criteria | Observations |
|---|------|-----------------|--------------|
| T1 | Log in and find where to create a lesson | Lands on dashboard, finds "New Lesson" without prompting | |
| T2 | Create a lesson: topic = "Julius Caesar", grade = their own grade level | Completes Step 1 without confusion | |
| T3 | Review the generated script | Can locate script, reads it, understands it | |
| T4 | Preview the avatar video (or audio) | Presses play successfully | |
| T5 | Publish the lesson and find the student share link | Finds publish button + share link | |
| T6 | Navigate to a pre-existing lesson and read the quiz questions | Can locate quiz, understands format | |

**Facilitator rules during tasks:**
- Stay silent unless participant is stuck > 2 min
- If stuck: ask "What are you expecting to happen here?" — never point
- Note every hesitation, re-read, or wrong click with a timestamp

---

## Part 2 — Post-Test Interview (~25 min)

Ask after all tasks. Record verbatim answers.

### A. Their Teaching Context

1. What subject(s) and grade level(s) do you teach?
2. How much history do you actually cover in a typical week?
3. Walk me through how you currently build a lesson — from idea to in front of students.
4. What's the most time-consuming part of lesson prep?
5. Do you use any video content today? YouTube? Documentary clips? What works, what doesn't?
6. How do your students currently engage with historical figures or narrative content?

---

### B. First Impressions

7. Before you touched anything — when you first heard "AI avatar of a historical figure narrates your lesson" — what was your gut reaction?
8. Did the product do what you expected from that description? What surprised you?
9. In one sentence: what does this product do?
   *(This tells you if your messaging is working.)*

---

### C. Usability & Friction

10. What was the most confusing moment during the test?
11. Was there any point where you weren't sure what to do next?
12. What would you change about the lesson creation flow?
13. The script the AI generated — how accurate/appropriate did it feel for your students? Too simple? Too complex?
14. If you were showing this to a skeptical colleague, what would you say first?

---

### D. Value Proposition

15. How long do you currently spend building a comparable lesson?
16. If this saved you [X] minutes per lesson — what would you do with that time?
17. Is the avatar narration a gimmick, or does it add real value? Why?
18. Do you trust AI-generated historical content enough to show it to students? What would make you trust it more?
19. Would you use this in your classroom next week if it was free? Why / why not?
20. Would you pay for it? What would a fair price feel like? (monthly, per class, per school)

---

### E. Adoption & Barriers

21. What would stop you from using this regularly?
22. Who else at your school would care about this — department head, curriculum coordinator, IT?
23. Would this need to go through an approval process at your school?
24. What would "success" look like for a tool like this in your classroom after one semester?
25. Is there a feature you wish existed that isn't here?

---

### F. Closing

26. On a scale of 1–10: how likely are you to use this when it's live?
27. What's the #1 thing we should fix before launch?
28. May we follow up with you after launch for feedback?
   - Email: ___________________________

---

## Observation Sheet (fill during tasks)

| Time | Task | What happened | Quote / reaction |
|------|------|--------------|-----------------|
| | | | |
| | | | |
| | | | |

**Top 3 usability issues:**
1.
2.
3.

**Strongest positive reaction (quote + context):**

**Biggest hesitation or doubt expressed:**

---

## After the Session

- [ ] Write up key findings within 24h (memory fades)
- [ ] Tag quotes: `[confusion]` `[delight]` `[doubt]` `[value]` `[blocker]`
- [ ] Log top friction points as GitHub issues
- [ ] Decide: does core value prop land? Y / N / Needs reframe
