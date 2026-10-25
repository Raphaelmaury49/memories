<template>
  <div class="partners">
    <p class="hint">
      {{
        t(
          'memories',
          'A partner sees all photos from your timeline folders in their own timeline, read-only. Your locked folder is never shared.',
        )
      }}
    </p>

    <div class="title">{{ t('memories', 'Sharing my photos with') }}</div>
    <ul v-if="data?.sharing_with.length" class="list">
      <li v-for="p of data.sharing_with" :key="p.uid">
        <NcAvatar :user="p.uid" :size="24" :show-user-status="false" />
        <span class="who">{{ p.displayname }}</span>
        <NcButton variant="tertiary" :aria-label="t('memories', 'Stop sharing')" @click="remove(p.uid)">
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcButton>
      </li>
    </ul>

    <div class="add">
      <NcTextField
        v-model="query"
        :label="t('memories', 'Add a partner')"
        :placeholder="t('memories', 'Search users')"
        autocomplete="off"
        @update:model-value="search"
      />
      <ul v-if="suggestions.length" class="suggestions">
        <li v-for="s of suggestions" :key="s.uid">
          <button @click="add(s.uid)">
            <NcAvatar :user="s.uid" :size="24" :show-user-status="false" />
            {{ s.label }}
          </button>
        </li>
      </ul>
    </div>

    <template v-if="data?.shared_with_me.length">
      <div class="title">{{ t('memories', 'Partners sharing with me') }}</div>
      <ul class="list">
        <li v-for="p of data.shared_with_me" :key="p.uid">
          <NcAvatar :user="p.uid" :size="24" :show-user-status="false" />
          <NcCheckboxRadioSwitch
            :model-value="p.in_timeline"
            type="switch"
            @update:model-value="(v: boolean) => toggle(p.uid, v)"
          >
            {{ t('memories', 'Show photos of {name} in my timeline', { name: p.displayname }) }}
          </NcCheckboxRadioSwitch>
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';

import NcAvatar from '@nextcloud/vue/components/NcAvatar';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';

import { t } from '@services/l10n';
import * as partners from '@services/partners';
import * as utils from '@services/utils/common';
import { RenewingTimeout } from '@services/utils/renewing-timeout';

import CloseIcon from 'vue-material-design-icons/Close.vue';

defineOptions({
  name: 'PartnersSettings',
});

const data = ref<partners.IPartners | null>(null);
const query = ref('');
const suggestions = ref<{ uid: string; label: string }[]>([]);
const timer = new RenewingTimeout();

onMounted(async () => {
  data.value = await run(partners.list());
});

async function run<T>(p: Promise<T>): Promise<T | null> {
  try {
    return await p;
  } catch (e: any) {
    utils.showError(e?.response?.data?.message ?? t('memories', 'Something went wrong'));
    return null;
  }
}

function search() {
  timer.set(async () => {
    const q = query.value.trim();
    if (q.length < 2) {
      suggestions.value = [];
      return;
    }
    try {
      const url = generateOcsUrl('apps/files_sharing/api/v1/sharees');
      const res = await axios.get(url, {
        params: { search: q, itemType: 'file', shareType: [0], perPage: 8, format: 'json' },
      });
      const users = [...(res.data?.ocs?.data?.exact?.users ?? []), ...(res.data?.ocs?.data?.users ?? [])];
      const taken = new Set([utils.uid, ...(data.value?.sharing_with.map((p) => p.uid) ?? [])]);
      suggestions.value = users
        .map((u: any) => ({ uid: String(u.value?.shareWith ?? ''), label: String(u.label ?? u.value?.shareWith) }))
        .filter((u) => u.uid && !taken.has(u.uid));
    } catch (e) {
      console.error(e);
    }
  }, 300);
}

async function add(uid: string) {
  suggestions.value = [];
  query.value = '';
  data.value = (await run(partners.add(uid))) ?? data.value;
}

async function remove(uid: string) {
  data.value = (await run(partners.remove(uid))) ?? data.value;
}

async function toggle(owner: string, show: boolean) {
  data.value = (await run(partners.setInTimeline(owner, show))) ?? data.value;
  utils.bus.emit('memories:timeline:hard-refresh', null);
}
</script>

<style lang="scss" scoped>
.partners {
  .hint {
    color: var(--color-text-maxcontrast);
    margin-bottom: 12px;
  }

  .title {
    font-weight: bold;
    margin: 12px 0 6px;
  }

  .list > li {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;

    .who {
      flex: 1;
    }
  }

  .suggestions {
    margin-top: 4px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);

    button {
      display: flex;
      align-items: center;
      gap: 8px;
      width: 100%;
      margin: 0;
      border: none;
      border-radius: 0;
      background: transparent;
      text-align: start;
      cursor: pointer;

      &:hover {
        background: var(--color-background-hover);
      }
    }
  }
}
</style>
