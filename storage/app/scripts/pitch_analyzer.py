import sys
import json
import librosa
import numpy as np

def analyze_pitch(file_path, target_note):
    try:
        # 1. Load the raw .wav audio file mapping time-domain data arrays
        y, sr = librosa.load(file_path, sr=22050)
        
        # 2. Execute the pYIN algorithm to extract fundamental frequency (f0) frames
        f0, voiced_flag, voiced_probs = librosa.pyin(
            y, fmin=librosa.note_to_hz('C3'), fmax=librosa.note_to_hz('C6'), sr=sr
        )
        
        # Filter out silent/unvoiced frames (NaN values)
        valid_pitches = f0[~np.isnan(f0)]
        
        if len(valid_pitches) == 0:
            return {"success": False, "error": "No vocal pitch or sound detected."}
        
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
            "is_correct": is_correct
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