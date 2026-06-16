import io
import torch
import sys
from fastapi import FastAPI, UploadFile, File, HTTPException
from PIL import Image
from transformers import VisionEncoderDecoderModel, ViTImageProcessor, AutoTokenizer

app = FastAPI(title="Laravel OMR Translation Engine")

# 1. Establish hardware fallback globally before running initialization
device = "cuda" if torch.cuda.is_available() else "cpu"

# ==============================================================================
# INITIALIZE HUGGING FACE TARGET VARIABLES
# ==============================================================================
print("⏳ Loading custom ViT-GPT2 sheet music model from Hugging Face...")
MODEL_NAME = "jamesFaber/transcoda-omr-model"  

try:
    # Load the corresponding image processors and tokenizers
    image_processor = ViTImageProcessor.from_pretrained("google/vit-base-patch16-224-in21k")
    tokenizer = AutoTokenizer.from_pretrained("gpt2")
    
    # Download and assemble your trained network weights
    model = VisionEncoderDecoderModel.from_pretrained(MODEL_NAME)
    
    # Force tokenizer special token configurations onto the generation config
    model.config.pad_token_id = tokenizer.pad_token_id or tokenizer.eos_token_id
    model.config.decoder_start_token_id = tokenizer.bos_token_id or tokenizer.eos_token_id
    
    # Move to designated accelerator card or native CPU
    model.to(device)
    print(f"✅ AI Server successfully compiled on host engine target: [{device}]")
except Exception as e:
    print(f"❌ CRITICAL: Error initializing model components: {str(e)}")
    # Stop the server startup if the model components cannot load
    sys.exit(1)

# ==============================================================================
# MAIN API TRANSCRIPTION ROUTE FOR LARAVEL HTTP REQUESTS
# ==============================================================================
@app.post("/api/v1/transcribe")
async def transcribe_sheet_music(file: UploadFile = File(...)):
    # Validate file parameters to guard against corrupt uploads
    if not file.content_type.startswith("image/"):
        raise HTTPException(
            status_code=400, 
            detail="Invalid file format. Please send a valid sheet music image stream."
        )
    
    try:
        # 1. Convert the raw file payload binary directly into an RGB image
        image_bytes = await file.read()
        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")
        
        # 2. Run image normalization preprocessing matching the ViT input scale
        pixel_values = image_processor(image, return_tensors="pt").pixel_values.to(device)
        
        # 3. Pass the tensors through the model with balanced OMR configurations
        with torch.no_grad():
            generated_ids = model.generate(
                pixel_values, 
                max_length=128,
                no_repeat_ngram_size=2,          # Lowers constraint limits slightly
                repetition_penalty=1.5,          # Balanced penalty to stop loops but allow tokens
                forced_bos_token_id=None,
            )
                
        # 4. Convert the model's numerical code outputs back into text string notation
        transcribed_text = tokenizer.decode(generated_ids[0], skip_special_tokens=True)
        
        # 5. Respond back to Laravel with a structured JSON payload
        return {
            "success": True,
            "notation": transcribed_text.strip()
        }
        
    except Exception as e:
        raise HTTPException(
            status_code=500, 
            detail=f"Internal AI processing failure: {str(e)}"
        )