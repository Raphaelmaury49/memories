import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';

export type ILockedStatus = {
  has_pin: boolean;
  unlocked: boolean;
  path: string;
  ttl: number;
};

export async function status(): Promise<ILockedStatus> {
  const res = await axios.get<ILockedStatus>(API.LOCKED());
  return res.data;
}

export async function lock(): Promise<void> {
  await axios.post(API.LOCKED('lock'));
}

/** Server message of a failed request, else a generic one. */
function errorText(e: any): string {
  return e?.response?.data?.message ?? t('memories', 'Something went wrong');
}

/** Ask for a new PIN twice and save it; the current PIN is required when changing. */
export async function choosePin(changing: boolean): Promise<boolean> {
  let current = '';
  if (changing) {
    current = (await utils.prompt({ title: t('memories', 'Current PIN'), password: true })) ?? '';
    if (!current) return false;
  }

  const pin = await utils.prompt({
    title: t('memories', 'Choose a PIN'),
    message: t('memories', 'Use 4 to 12 digits. You will need it to open the locked folder.'),
    password: true,
  });
  if (!pin) return false;

  const again = await utils.prompt({ title: t('memories', 'Confirm PIN'), password: true });
  if (again !== pin) {
    utils.showError(t('memories', 'The PINs do not match'));
    return false;
  }

  try {
    await axios.put(API.LOCKED('pin'), { pin, current });
    return true;
  } catch (e) {
    utils.showError(errorText(e));
    return false;
  }
}

/**
 * Make sure the locked folder is open for this session,
 * prompting for (or creating) the PIN. Resolves false if the user gave up.
 */
export async function ensureUnlocked(): Promise<boolean> {
  const st = await status();
  if (st.unlocked) return true;
  if (!st.has_pin) return await choosePin(false);

  for (;;) {
    const pin = await utils.prompt({
      title: t('memories', 'Locked folder'),
      message: t('memories', 'Enter your PIN to view the locked folder.'),
      name: t('memories', 'PIN'),
      password: true,
    });
    if (!pin) return false;

    try {
      await axios.post(API.LOCKED('unlock'), { pin });
      return true;
    } catch (e) {
      utils.showError(errorText(e));
    }
  }
}
