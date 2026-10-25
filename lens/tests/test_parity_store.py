"""Store queries added for OCR, similar and duplicates, against in-memory Qdrant."""

import math

import pytest
from qdrant_client import AsyncQdrantClient

from store import EmbeddingStore, FileMeta, UpsertPoint

DIM = 8


def vec(*xs):
    """Pad and L2-normalize a short vector."""

    v = list(xs) + [0.0] * (DIM - len(xs))
    n = math.sqrt(sum(x * x for x in v))
    return [x / n for x in v]


def point(fileid, v, parent=10, epoch=None):
    """Upsert payload with display metadata."""

    meta = FileMeta(w=100, h=80, etag="e", mimetype="image/jpeg", epoch=epoch or fileid, dayid=1, mtime=fileid)
    return UpsertPoint(fileid=fileid, vector=v, parent_id=parent, meta=meta)


@pytest.fixture
async def store():
    """Fresh collection with five files: two near-duplicate pairs and one outlier."""

    client = AsyncQdrantClient(location=":memory:")
    s = EmbeddingStore(client, DIM)
    await s.ensure_collection()
    await s.upsert_many([
        point(1, vec(1, 0.01)),
        point(2, vec(1, 0.02)),
        point(3, vec(0, 1, 0.01)),
        point(4, vec(0, 1, 0.02)),
        point(5, vec(0, 0, 1)),
        point(6, vec(1, 0.015), parent=99),
    ])
    yield s
    await client.close()


async def test_duplicates_groups_pairs_and_respects_scope(store):
    groups = await store.duplicates(folders=[10], threshold=0.99, neighbors=8, page=2)
    ids = sorted(sorted(p["fileid"] for p in g) for g in groups)
    assert ids == [[1, 2], [3, 4]]


async def test_duplicates_threshold(store):
    assert await store.duplicates(folders=[10], threshold=0.9999999, neighbors=8) == []


async def test_similar_excludes_self_and_other_folders(store):
    hits = await store.similar(fileid=1, folders=[10], limit=10)
    ids = [h["fileid"] for h in hits]
    assert ids[0] == 2
    assert 1 not in ids and 6 not in ids


async def test_ocr_roundtrip_and_reindex_clears(store):
    pending = await store.ocr_pending(version=1, limit=100)
    assert {p["fileid"] for p in pending} == {1, 2, 3, 4, 5, 6}

    await store.set_ocr(1, 1, "Facture Électricité EDF", 1)
    await store.set_ocr(2, 999, "stale mtime is ignored", 1)
    pending = {p["fileid"] for p in await store.ocr_pending(version=1, limit=100)}
    assert 1 not in pending and 2 in pending

    hits = await store.search_ocr(text="facture", vector=vec(1), folders=[10], limit=10)
    assert [h["fileid"] for h in hits] == [1]
    assert "ocr" not in hits[0]

    await store.upsert_many([point(1, vec(1, 0.01))])
    assert 1 in {p["fileid"] for p in await store.ocr_pending(version=1, limit=100)}
