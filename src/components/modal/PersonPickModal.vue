<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ title }}
    </template>

    <FaceList :backend="backend" :exclude="exclude" @select="pick" />

    <template #buttons>
      <NcButton @click="close" class="button">
        {{ t('memories', 'Cancel') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import FaceList from './FaceList.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';

import type { IFace } from '@typings';
import type { PeopleBackend } from '@services/people';

defineOptions({
  name: 'PersonPickModal',
});

const emit = defineEmits<{
  (e: 'select', face: IFace): void;
}>();

const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const title = ref(String());
const backend = ref<PeopleBackend>('recognize');
const exclude = ref<string[]>([]);

/** Pick one person of the backend, leaving out the given names/ids. */
function open(opts: { title: string; backend: PeopleBackend; exclude: string[] }) {
  title.value = opts.title;
  backend.value = opts.backend;
  exclude.value = opts.exclude;
  show.value = true;
}

async function pick(face: IFace) {
  await close();
  emit('select', face);
}

function cleanup() {
  show.value = false;
}

defineExpose({ open });
</script>
