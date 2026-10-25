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
