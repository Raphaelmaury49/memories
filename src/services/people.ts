import axios from '@nextcloud/axios';

import { API } from '@services/API';

/** Person backends that support birthdates and multi-person filtering. */
export type PeopleBackend = 'recognize' | 'facerecognition';

export type IPersonMeta = {
  cluster_id: string;
  birthdate: string | null;
};

/** Birthdate and stable id of one person (route name or id). */
export async function getMeta(backend: PeopleBackend, name: string): Promise<IPersonMeta> {
  const res = await axios.get<IPersonMeta>(API.PEOPLE_META(backend, name));
  return res.data;
}

/** Set (YYYY-MM-DD) or clear (empty) a birthdate. */
export async function setBirthdate(backend: PeopleBackend, name: string, birthdate: string): Promise<IPersonMeta> {
  const res = await axios.put<IPersonMeta>(API.PEOPLE_BIRTHDATE(backend, name), { birthdate });
  return res.data;
}

/** Whole years between a YYYY-MM-DD birthdate and a date; null if before birth. */
export function ageAt(birthdate: string, at: Date): number | null {
  const [y, m, d] = birthdate.split('-').map(Number);
  if (!y || !m || !d) return null;

  let age = at.getUTCFullYear() - y;
  const beforeBirthday = at.getUTCMonth() + 1 < m || (at.getUTCMonth() + 1 === m && at.getUTCDate() < d);
  if (beforeBirthday) age--;

  return age >= 0 ? age : null;
}

/** Route names of the "together" view as a list. */
export function parseTogetherIds(ids: unknown): string[] {
  const raw = Array.isArray(ids) ? ids.join(',') : String(ids ?? '');
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

export type IMergePair = { a: number; b: number; distance: number };

/** Recognize people that probably are the same person, closest first. */
export async function getMergeSuggestions(limit = 20): Promise<{ threshold: number; pairs: IMergePair[] }> {
  const res = await axios.get<{ threshold: number; pairs: IMergePair[] }>(API.PEOPLE_MERGE_SUGGESTIONS(), {
    params: { limit },
  });
  return res.data;
}

/** Move every face of one Recognize person into another. */
export async function mergePeople(from: number, to: number): Promise<void> {
  await axios.post(API.PEOPLE_MERGE(), { from, to });
}

/** Never suggest this pair again. */
export async function dismissMerge(a: number, b: number): Promise<void> {
  await axios.post(API.PEOPLE_MERGE_DISMISS(), { a, b });
}
