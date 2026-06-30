import io
import os
import base64
import glob
import shutil
import tempfile
import subprocess
from fractions import Fraction

from fastapi import FastAPI, UploadFile, File, HTTPException
from music21 import converter as m21_converter

app = FastAPI(title="Laravel OMR Translation Engine")

print("Loading oemer OMR engine...")


def _pitch_to_abc(pitch) -> str:
    step = pitch.step
    octave = pitch.octave if pitch.octave is not None else 4

    accidental = ""
    if pitch.accidental is not None:
        alter = pitch.accidental.alter
        if alter == 1:
            accidental = "^"
        elif alter == 2:
            accidental = "^^"
        elif alter == -1:
            accidental = "_"
        elif alter == -2:
            accidental = "__"
        elif alter == 0:
            accidental = "="

    if octave >= 5:
        letter = step.lower()
        octave_marks = "'" * (octave - 5)
    else:
        letter = step.upper()
        octave_marks = "," * (4 - octave)

    return f"{accidental}{letter}{octave_marks}"


def _ratio_to_abc_length(ratio: float) -> str:
    if abs(ratio - 1) < 1e-6:
        return ""
    frac = Fraction(ratio).limit_denominator(16)
    if frac.denominator == 1:
        return str(frac.numerator)
    if frac.numerator == 1:
        return f"/{frac.denominator}"
    return f"{frac.numerator}/{frac.denominator}"


def musicxml_to_abc(score) -> str:
    """Hand-rolled MusicXML -> ABC serializer (music21 can parse ABC but cannot write it)."""
    part = score.parts[0] if score.parts else score

    time_sig = part.recurse().getElementsByClass("TimeSignature").first()
    meter = f"{time_sig.numerator}/{time_sig.denominator}" if time_sig else "4/4"

    default_ql = 0.5  # L:1/8
    lines = ["X:1", "T:Transcribed", f"M:{meter}", "L:1/8", "K:C"]

    measures = part.getElementsByClass("Measure")
    iterable = measures if len(measures) else [part]

    measure_strs = []
    for measure in iterable:
        tokens = []
        for el in measure.notesAndRests:
            ratio = el.duration.quarterLength / default_ql if default_ql else 1
            length_str = _ratio_to_abc_length(ratio)

            if el.isRest:
                tokens.append(f"z{length_str}")
                continue

            pitches = el.pitches if el.isChord else [el.pitch]
            pitch_tokens = [_pitch_to_abc(p) for p in pitches]
            if el.isChord:
                tokens.append("[" + "".join(pitch_tokens) + "]" + length_str)
            else:
                tokens.append(pitch_tokens[0] + length_str)

        if tokens:
            measure_strs.append(" ".join(tokens))

    lines.append(" | ".join(measure_strs) + " |")
    return "\n".join(lines)


def score_to_midi_base64(score) -> str | None:
    try:
        tmp_path = tempfile.mktemp(suffix=".mid")
        score.write("midi", fp=tmp_path)
        with open(tmp_path, "rb") as f:
            midi_bytes = f.read()
        os.unlink(tmp_path)
        return base64.b64encode(midi_bytes).decode("utf-8")
    except Exception:
        return None


@app.post("/api/v1/transcribe")
async def transcribe_sheet_music(file: UploadFile = File(...)):
    if not file.content_type.startswith("image/"):
        raise HTTPException(
            status_code=400,
            detail="Invalid file format. Please send a valid sheet music image stream.",
        )

    work_dir = tempfile.mkdtemp(prefix="oemer_")
    try:
        image_bytes = await file.read()
        ext = os.path.splitext(file.filename or "")[1].lower()
        if ext not in (".png", ".jpg", ".jpeg"):
            ext = ".png"
        image_path = os.path.join(work_dir, f"input{ext}")
        with open(image_path, "wb") as f:
            f.write(image_bytes)

        out_dir = os.path.join(work_dir, "out")
        os.makedirs(out_dir, exist_ok=True)

        result = subprocess.run(
            ["oemer", image_path, "-o", out_dir],
            capture_output=True,
            text=True,
            timeout=300,
        )

        musicxml_files = glob.glob(os.path.join(out_dir, "*.musicxml"))
        if not musicxml_files:
            raise HTTPException(
                status_code=422,
                detail="OMR engine could not detect valid musical notation in the image. "
                + result.stderr[-500:],
            )

        score = m21_converter.parse(musicxml_files[0])

        abc_text = musicxml_to_abc(score)
        midi_base64 = score_to_midi_base64(score)

        return {
            "success": True,
            "notation": abc_text,
            "midi_base64": midi_base64,
        }

    except HTTPException:
        raise
    except subprocess.TimeoutExpired:
        raise HTTPException(status_code=504, detail="OMR processing timed out.")
    except Exception as e:
        raise HTTPException(
            status_code=500, detail=f"Internal OMR processing failure: {str(e)}"
        )
    finally:
        shutil.rmtree(work_dir, ignore_errors=True)
