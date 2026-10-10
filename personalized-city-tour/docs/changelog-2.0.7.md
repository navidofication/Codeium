# Personalized City Tour 2.0.7

## Image generation fallback

- Added Pollinations as a first-class image provider using its OpenAI-compatible image endpoint.
- Added a separate encrypted Hugging Face API key and text-to-image fallback using the HF Inference provider.
- Added automatic fallback chain: selected provider → Pollinations → Hugging Face → Sandbox.
- Added settings for the fallback toggle and Hugging Face image model.
- Kept existing OpenAI/custom providers compatible.
- Improved image response parsing and error reporting.
- Existing Groq AI and Google Drive settings are preserved.
