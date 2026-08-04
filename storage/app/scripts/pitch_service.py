import os
import tempfile

from fastapi import FastAPI, UploadFile, File, Form

from pitch_analyzer import analyze_pitch

app = FastAPI()


@app.post("/api/v1/analyze")
async def analyze(audio: UploadFile = File(...), target_note: str = Form(...)):
    suffix = os.path.splitext(audio.filename or "")[1] or ".wav"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(await audio.read())
        tmp_path = tmp.name

    try:
        return analyze_pitch(tmp_path, target_note)
    finally:
        os.unlink(tmp_path)
