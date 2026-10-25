"""Background OCR pass: attach recognized text to already-indexed images."""

import asyncio
import io
import logging
import time

from PIL import Image, ImageOps

from config import config
from nextcloud import NotFoundError, fetch_file

log = logging.getLogger("lens.ocr")

# Transient fetch errors are retried after this many seconds.
RETRY_DELAY = 60 * 60

# The indexer has priority; OCR yields while its queue is non-empty.
BUSY_SLEEP = 5


def decode_upright(data: bytes) -> Image.Image:
    """Decode to RGB with EXIF orientation applied; OCR is orientation-sensitive."""

    image = Image.open(io.BytesIO(data))

    return ImageOps.exif_transpose(image).convert("RGB")


class OcrWorker:
    """Scroll images lacking current-version OCR, recognize, store; never blocks indexing."""

    def __init__(self, model, store, queue):
        self.model = model
        self.store = store
        self.queue = queue
        self.retry: dict[int, float] = {}
        self.stats = {"done_total": 0, "empty_total": 0, "failed_total": 0, "pending": None}

    async def run(self):
        """Loop forever; idle sleeps when everything is recognized."""

        while True:
            try:
                did = await self.step()
            except asyncio.CancelledError:
                raise
            except Exception:  # noqa: BLE001
                log.exception("ocr step failed")
                did = False

            if not did:
                await asyncio.sleep(config.ocr.interval)

    async def step(self) -> bool:
        """Process one batch; return False when nothing could be done."""

        if self.queue.depth or self.queue.in_flight:
            await asyncio.sleep(BUSY_SLEEP)
            return True

        now = time.time()
        self.retry = {k: v for k, v in self.retry.items() if v > now}

        limit = config.ocr.batch_size + len(self.retry)
        candidates = await self.store.embedding.ocr_pending(config.ocr.version, limit)
        pending = [p for p in candidates if p["fileid"] not in self.retry]
        self.stats["pending"] = len(pending)

        if not pending:
            return False

        for payload in pending[: config.ocr.batch_size]:
            await self._one(payload["fileid"], payload.get("mtime"))

        return True

    async def _one(self, fileid: int, mtime: int | None):
        """Fetch, recognize and store one file; permanent failures store empty text."""

        try:
            res = await asyncio.to_thread(fetch_file, fileid)
        except NotFoundError:
            await self.store.embedding.set_ocr(fileid, mtime, "", config.ocr.version)
            return
        except Exception:  # noqa: BLE001
            log.warning("ocr fetch failed for %d, retry later", fileid, exc_info=True)
            self.retry[fileid] = time.time() + RETRY_DELAY
            self.stats["failed_total"] += 1
            return

        try:
            image = await asyncio.to_thread(decode_upright, res.data)
            text = await self.model.recognize_async(image)
        except Exception:  # noqa: BLE001
            log.warning("ocr failed for %d, storing empty text", fileid, exc_info=True)
            text = ""
            self.stats["failed_total"] += 1

        await self.store.embedding.set_ocr(fileid, mtime, text, config.ocr.version)

        self.stats["done_total"] += 1
        if not text:
            self.stats["empty_total"] += 1

        log.info("ocr %d: %d chars", fileid, len(text))
