import axios from '@nextcloud/axios';

import { API } from '@services/API';

export type IPartners = {
  /** Users my timeline is shared with */
  sharing_with: { uid: string; displayname: string }[];
  /** Users sharing their timeline with me */
  shared_with_me: { uid: string; displayname: string; in_timeline: boolean }[];
};

export async function list(): Promise<IPartners> {
  return (await axios.get<IPartners>(API.PARTNERS())).data;
}

export async function add(partner: string): Promise<IPartners> {
  return (await axios.post<IPartners>(API.PARTNERS(), { partner })).data;
}

export async function remove(partner: string): Promise<IPartners> {
  return (await axios.delete<IPartners>(API.PARTNER(partner))).data;
}

export async function setInTimeline(owner: string, show: boolean): Promise<IPartners> {
  return (await axios.put<IPartners>(API.PARTNER_TIMELINE(owner), { show })).data;
}
