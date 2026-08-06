import sys
import json
import librosa
import numpy as np

# pYIN reports a per-frame probability that voicing is genuinely present.
# Background noise/room hum can still produce a non-NaN f0 guess, it just
# won't be a confident one - so a frame only counts as a real pitch reading
# once it clears this threshold, not merely once it's non-NaN.
#
# Calibration note: synthetic testing (clean tone vs. tone + noise) showed
# pYIN's own probability output collapses sharply rather than gradually once
# noise crosses a certain point, so the exact cutoff here matters less than
# it might for a smoother confidence signal. Validated against low-frequency
# hum/rumble (mains hum, HVAC, mobile mic self-noise, the noise profile
# preemphasis above specifically targets) up to the sung tone's own
# amplitude with no false rejections. Not yet validated against real device
# recordings in the field - revisit this value once that data exists.
#
# Field report (2026-08-05): a deep/bass voice was rejected here while a
# high voice wasn't. Root cause was fmin being too high (see below), not
# this threshold - a fundamental outside the search range reads as ~0
# confidence regardless of audio quality, which looks identical to "too
# noisy" from this constant's perspective. Confirmed via synthetic ~110Hz
# tone: raising fmin's floor alone took mean confidence from ~0.01 to ~0.74
# with no change to this threshold.
VOICED_CONFIDENCE_THRESHOLD = 0.5

# Minimum fraction of analysis frames that must clear both the voiced flag
# and the confidence threshold above for a clip to be trusted at all. Below
# this the mic likely picked up mostly background noise/silence rather than
# a sung note, so the clip is rejected instead of returning a pitch reading
# that's really just measuring noise.
MIN_CONFIDENT_VOICED_RATIO = 0.15

def analyze_pitch(file_path, target_note):
    try:
        # 1. Load the raw .wav audio file mapping time-domain data arrays
        y, sr = librosa.load(file_path, sr=22050)

        # Trim leading/trailing near-silence (button-press noise, room tone
        # before the singer starts) so it doesn't dilute the confident-voiced
        # ratio below or bias the analysis window toward non-singing audio.
        y, _ = librosa.effects.trim(y, top_db=30)

        if y.size == 0:
            return {"success": False, "error": "No audio detected - the clip was silent."}

        # Pre-emphasis (high-frequency boost) raises the sung pitch's energy
        # relative to low-frequency background noise (room hum, HVAC, mobile
        # mic self-noise), which otherwise biases pYIN toward locking onto
        # that noise instead of the singer on noisy recordings.
        y = librosa.effects.preemphasis(y)

        # 2. Execute the pYIN algorithm to extract fundamental frequency (f0) frames.
        # fmin/fmax (C2-C6) span deep bass through soprano range. This floor
        # matters more than it looks: singers commonly render a target note a
        # comfortable octave down (see the octave-fold below), and a fundamental
        # that lands below fmin isn't just "low confidence" - pYIN can't search
        # there at all, so confidence collapses to ~0 regardless of audio
        # quality. C3 (~131Hz) was too high a floor for real bass voices, whose
        # fundamental can sit well under 130Hz; C2 (~65Hz) gives real headroom.
        # Pitch tracking itself is largely timbre-agnostic within this range -
        # pYIN tracks periodicity, not spectral envelope, so it isn't thrown
        # off by voice "color" the way a formant-based method would be.
        f0, voiced_flag, voiced_probs = librosa.pyin(
            y, fmin=librosa.note_to_hz('C2'), fmax=librosa.note_to_hz('C6'), sr=sr
        )

        # Noise gate: only trust frames that are both non-NaN AND confidently
        # voiced, rather than every frame pYIN didn't outright fail on.
        confident_mask = (~np.isnan(f0)) & voiced_flag & (voiced_probs >= VOICED_CONFIDENCE_THRESHOLD)
        confident_ratio = float(np.count_nonzero(confident_mask)) / max(len(f0), 1)

        if confident_ratio < MIN_CONFIDENT_VOICED_RATIO:
            return {
                "success": False,
                "error": "Couldn't get a clear pitch reading - try again somewhere quieter or closer to the mic.",
                "confidence": round(confident_ratio, 2),
            }

        valid_pitches = f0[confident_mask]

        # 3. Calculate the median fundamental frequency of the singing clip
        detected_hz = float(np.median(valid_pitches))
        
        # Get target frequency
        target_hz = float(librosa.note_to_hz(target_note))
        
        # 4. Calculate deviation metric in Cents (100 cents = 1 semitone)
        # Using standard acoustics formula: cents = 1200 * log2(f2 / f1)
        raw_cents_deviation = float(1200 * np.log2(detected_hz / target_hz))

        # Fold to the nearest octave: pYIN can lock onto a subharmonic and
        # report a pitch exactly an octave below what was actually sung, and
        # singers commonly match the right pitch class in a more comfortable
        # octave anyway. Neither should register as being ~1200 cents flat.
        octaves_off = round(raw_cents_deviation / 1200)
        cents_deviation = raw_cents_deviation - (octaves_off * 1200)

        # Threshold Evaluation aligned conceptually with the Online Music Education Dataset
        # If they are within 50 cents (half a semitone) of the target note, they pass!
        is_correct = bool(abs(cents_deviation) <= 50)
        
        return {
            "success": True,
            "detected_frequency": round(detected_hz, 2),
            "target_frequency": round(target_hz, 2),
            "cents_deviation": round(cents_deviation, 1),
            "is_correct": is_correct,
            "confidence": round(confident_ratio, 2)
        }
        
    except Exception as e:
        return {"success": False, "error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Missing arguments."}))
        sys.exit(1)
        
    audio_path = sys.argv[1]
    target_pitch = sys.argv[2]
    
    output = analyze_pitch(audio_path, target_pitch)
    print(json.dumps(output))