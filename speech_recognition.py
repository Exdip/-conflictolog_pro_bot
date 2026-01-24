import requests
import io
from pydub import AudioSegment
import config  # <-- Убедитесь, что импорт правильный

def convert_opus_to_wav(opus_file_path):
    """Преобразует .oga (Opus) в .wav (WAV)"""
    audio = AudioSegment.from_file(opus_file_path, format="ogg")
    wav_buffer = io.BytesIO()
    audio.export(wav_buffer, format="wav")
    return wav_buffer.getvalue()

def voice_to_text_yandex(opus_file_path):
    """Распознаёт голосовое сообщение через Yandex SpeechKit"""
    # Преобразуем Opus в WAV
    wav_data = convert_opus_to_wav(opus_file_path)

    headers = {
        "Authorization": f"Api-Key {config.YANDEX_API_KEY}",  # <-- Используем config
        "Content-Type": "audio/wav"
    }

    params = {
        "folderId": Config.YANDEX_FOLDER_ID,  # <-- Используем config
        "lang": "ru-RU"
    }

    response = requests.post(
        "https://stt.api.cloud.yandex.net/speech/v1/stt:recognize",
        headers=headers,
        params=params,
        data=wav_data
    )

    if response.status_code == 200:
        result = response.json()
        return result.get("result", "")
    else:
        print(f"Ошибка: {response.status_code}, {response.text}")
        return ""