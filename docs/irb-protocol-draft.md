# Aura Adaptive Feedback Study — Research Protocol (Draft)

> **This is a starting draft, not a submission-ready document.** It's built from
> the actual system as implemented, so the technical/methodological sections
> are accurate. Everything marked `[FILL IN]` is something only you (with your
> advisor) can determine — I don't know your institution's specific IRB forms,
> submission process, or requirements. Contact your university's research
> ethics/IRB office directly to confirm the real process before submitting
> anything or enrolling participants.

## 1. Study Information

- **Title:** [FILL IN — working title: "Effects of Multi-Modal AI Tutoring on Pitch Accuracy and Transcription Skill in Beginner Music Students"]
- **Principal Investigator:** [FILL IN — your name]
- **Faculty Advisor:** [FILL IN]
- **Institution:** [FILL IN — confirm with your advisor]
- **Anticipated study window:** 2-3 weeks per participant cohort, ~3 sessions/week

## 2. Purpose & Research Questions

**Primary Pedagogical RQ:** Does multi-modal AI tutoring (DSP-based pitch analysis + LLM-generated feedback) improve pitch accuracy and transcription speed in beginner music students, compared to static/canned ear-training feedback?

**Pedagogical Sub-Question:** Does adaptive grading logic (competence-gated content unlocking) affect flow state and cognitive load during practice, compared to a fixed/static exercise sequence?

*(A third, purely technical sub-question about DSP/OMR latency on mobile architecture is answered via a separate offline system benchmark, not through human-subjects data collection, so it isn't part of this protocol.)*

## 3. Study Design

- **Design:** Randomized controlled trial, two parallel arms, pretest-posttest structure.
- **Arms:**
  - **Control:** Fixed, pre-written ("canned") feedback; full, unrestricted access to all exercises.
  - **Experimental:** AI-generated (LLM), personalized feedback; the Transcription exercise is locked until the participant demonstrates consistent pitch-matching accuracy (grounded in Gordon's Music Learning Theory — audiation before symbolic notation).
- **Randomization:** Participants are randomly assigned to an arm at the moment they consent to join the study (block-randomized to keep arm sizes balanced), and never reassigned.
- **Blinding:** Participants are told they will be assigned to one of two feedback/learning pathways and will not be told which one. The app never reveals arm assignment through any user-facing screen or API response.
- **Baseline (pretest):** Before any arm-specific feedback or gating logic activates, every participant — regardless of arm — completes an identical, fixed baseline assessment: 10 pitch-matching trials plus one transcription item, scored the same way for everyone, with neutral (non-arm-specific) feedback only.

## 4. Participants

- **Target population:** [FILL IN — define precisely, e.g. "adults aged 18+ who self-identify as beginner music students with fewer than 1 year of formal instruction"]
- **Recruitment method:** [FILL IN — e.g. flyers, social media, course announcements. Describe exactly how and where you will recruit.]
- **Sample size:** Target 30-40 participants (15-20 per arm). Minimum viable: 20 (10 per arm). If final enrollment is under 30, results will be explicitly framed as a pilot study rather than a fully powered trial.
- **Inclusion criteria:** [FILL IN]
- **Exclusion criteria:** [FILL IN — consider hearing impairments, age restrictions, prior advanced musical training if that's a confound you want to exclude]
- **Voluntariness:** Participation is optional and separate from ordinary use of the underlying app. Declining does not affect access to the app's non-study features. Participants may withdraw at any time without penalty; instructions for withdrawal: [FILL IN]
- **Compensation:** [FILL IN — none, or describe any incentive]

## 5. Procedures

1. Participant creates an account and is shown the informed consent screen on first login.
2. If they consent, they are randomly assigned to an arm (not disclosed to them) and immediately begin the one-time baseline assessment (10 fixed pitch trials + 1 fixed transcription item, ~5-10 minutes).
3. Once baseline is complete, normal practice becomes available: Free Practice (pitch-matching) and, once unlocked (experimental arm) or immediately (control arm), Transcription.
4. Participants are asked to practice approximately 3 times per week for 2-3 weeks (6-9 sessions).
5. After each day's first completed practice attempt, participants may optionally see a short 2-item check-in (perceived absorption and mental demand), at most once per day.
6. At approximately the study's halfway point, the researcher performs an internal data-quality spot-check (not participant-facing) to confirm telemetry is recording correctly.
7. At the end of the study window, all data is exported into a locked, read-only snapshot before any statistical analysis begins.

## 6. Data Collected

- Account information: name, email (used for login only).
- Audio recordings of singing (used for automated pitch analysis; **note:** voice recordings can be identifying — describe storage/deletion plan in Section 7).
- Pitch accuracy measurements (cents deviation from target note), per attempt.
- Transcription attempts (submitted note sequences, correctness scoring, elapsed time).
- Optional qualitative feedback (1-5 rating + free-text comment) after practice attempts.
- Daily flow/cognitive-load check-in (two 1-5 ratings).
- Study arm assignment and enrollment/completion timestamps (visible to the researcher only, never to the participant).

## 7. Data Storage, Security & Retention

- **Storage location:** [FILL IN — e.g. university-hosted server, PostgreSQL database, describe hosting]
- **Access control:** Only the researcher (and advisor, if applicable) can access identifiable study data; participants cannot see other participants' data or their own arm assignment.
- **Retention period:** [FILL IN]
- **De-identification for analysis/publication:** [FILL IN — describe how data will be anonymized/pseudonymized before analysis or reporting]
- **Audio recordings specifically:** [FILL IN — will raw audio be retained, deleted after analysis, or anonymized? This is the most sensitive data type collected and IRB reviewers typically ask about it directly.]

## 8. Risks & Benefits

- **Risks:** Minimal. Possible mild frustration or performance anxiety from being evaluated on singing accuracy; standard data-privacy risk associated with any digitally stored personal data and voice recordings.
- **Benefits:** Free access to a structured ear-training/pitch-practice tool; indirect contribution to music-education research.
- **Risk mitigation:** [FILL IN — e.g. participants can skip any exercise, withdraw at any time, audio is used only for pitch analysis]

## 9. Confidentiality

- Study arm assignment is never disclosed to the participant, preserving blinding throughout the study.
- [FILL IN — describe who besides the PI/advisor may see identifiable data, if anyone]

## 10. Informed Consent Process

Consent is obtained in-app before any study participation begins, via a dedicated consent screen that explicitly discloses:
- The study is a randomized controlled trial evaluating AI-generated feedback vs. standard feedback.
- Participants will be randomly assigned to one of two pathways and will not be told which one, for the duration of the study.
- What data is collected (practice attempts, audio, timing, optional feedback).
- Participation is voluntary, declining doesn't affect normal app use, and withdrawal is allowed at any time without penalty.

*(The actual consent screen currently has placeholder `[Institution]` / `[email]` text in its ethics-review line — this must be replaced with real institutional/IRB contact details before any participant sees it.)*

## 11. Anticipated Timeline

- [FILL IN — recruitment start date, study window dates, analysis phase]
