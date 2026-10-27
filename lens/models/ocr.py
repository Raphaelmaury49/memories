"""OCR backend: RapidOCR (PP-OCR ONNX), models pinned by the rapidocr package version."""

import asyncio
import logging
import os

import numpy as np
from PIL import Image

from config import config
from models.common import inference_sem

log = logging.getLogger("lens.ocr")

# Longest side fed to the detector; keeps receipts legible without OOM on 50MP shots.
MAX_SIDE = 2000


class OcrModel:
    """One RapidOCR engine shared by the OCR worker; text out, never boxes."""

    def __init__(self):
        """Empty state; call load before recognizing."""

        self._engine = None
        self._device = "pending"

    def model_dir(self) -> str:
        """Local model dir inside the shared cache."""

        return os.path.join(config.model_cache_dir, "rapidocr")

    def load(self) -> None:
        """Build the engine and run it once so every model is downloaded and verified."""

        from rapidocr import ModelType, OCRVersion, RapidOCR  # noqa: PLC0415

        os.makedirs(self.model_dir(), exist_ok=True)

        # rapidocr only accepts enum members for model_type / ocr_version.
        model_type = ModelType(config.ocr.model_type)

        params = {
            "Global.model_root_dir": self.model_dir(),
            "Global.log_level": "warning",
            "Global.max_side_len": MAX_SIDE,
            "Global.text_score": config.ocr.min_score,
            "Det.model_type": model_type,
            "Det.ocr_version": OCRVersion.PPOCRV6,
            "Rec.model_type": model_type,
            "Rec.ocr_version": OCRVersion.PPOCRV6,
        }

        if config.torch_num_threads:
            params["EngineConfig.onnxruntime.intra_op_num_threads"] = config.torch_num_threads

        self._engine = RapidOCR(params=params)
        self._engine(np.full((64, 256, 3), 255, dtype=np.uint8))
        self._device = "cpu"

        log.info("ocr engine loaded (%s) from %s", config.ocr.model_type, self.model_dir())

    def recognize(self, image: Image.Image) -> str:
        """Return recognized lines joined by newlines, empty when nothing legible."""

        res = self._engine(np.asarray(image.convert("RGB"))[:, :, ::-1])
        txts = getattr(res, "txts", None) or ()

        text = "\n".join(t.strip() for t in txts if t and t.strip())

        return text[: config.ocr.max_chars]

    async def recognize_async(self, image: Image.Image) -> str:
        """Serialize OCR through the global inference semaphore."""

        async with inference_sem:
            return await asyncio.to_thread(self.recognize, image)

    def device(self) -> str:
        """Execution device label for health output."""

        return self._device
