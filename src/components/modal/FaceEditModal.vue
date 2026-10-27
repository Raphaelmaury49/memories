<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Rename person') }}
    </template>

    <div class="fields">
      <NcTextField
        class="field"
        :autofocus="true"
        v-model="rawInput"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        @keypress.enter="save()"
      />
    </div>

    <template #buttons>
      <NcButton class="button" variant="primary" :disabled="!canSave" @click="save">
        {{ t('memories', 'Update') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@services/utils/dialog';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { useRouteIs } from '@services/route-is';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';
import * as people from '@services/people';

defineOptions({
  name: 'FaceEditModal',
});

const route = useRoute();
const router = useRouter();
const routeIs = useRouteIs();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const rawInput = ref(String());
const saving = ref(false);
const abort = useAbort();

const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());

const input = computed(() => {
  // Prevent leading and trailing spaces in name
  // https://github.com/pulsejet/memories/issues/1074
  return rawInput.value.trim();
});

const canSave = computed(
  () => input.value && name.value !== input.value && isNaN(Number(input.value)) && !saving.value,
);

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can update this person', { user: user.value }));
    return;
  }

  rawInput.value = isNaN(Number(name.value)) ? (name.value ?? String()) : String();
  show.value = true;
}

function cleanup() {
  abort.abort();
  show.value = false;
  saving.value = false;
}

async function save() {
  if (!canSave.value || saving.value) return;
  saving.value = true;
  const signal = abort.renew();

  try {
    if (routeIs.Recognize) {
      // Recognize refuses a name that is already taken: offer to merge instead
      const faces = await dav.getFaceList('recognize', { signal });
      const target = faces.find((f) => f.user_id === utils.uid && String(f.name) === input.value);
      if (target) {
        const ok = await utils.confirmDestructive({
          title: t('memories', 'Merge faces'),
          message: t('memories', '{name} already exists. Merge this person into {name}?', { name: input.value }),
          confirm: t('memories', 'Merge'),
          cancel: t('memories', 'Cancel'),
        });
        if (!ok) return;

        const meta = await people.getMeta('recognize', name.value);
        await people.mergePeople(Number(meta.cluster_id), Number(target.cluster_id));
      } else {
        await dav.recognizeRenameFace(user.value, name.value, input.value, { signal });
      }
    } else {
      await dav.faceRecognitionRenamePerson(name.value, input.value, { signal });
    }
    signal.throwIfAborted();

    await close();
    await router.replace({
      name: route.name?.toString(),
      params: { user: user.value, name: input.value },
    });
  } catch (error) {
    if (isAbortError(error)) return;
    console.error(error);
    showError(
      t('memories', 'Failed to rename {oldName} to {name}.', {
        oldName: name.value,
        name: input.value,
      }),
    );
  } finally {
    if (!signal.aborted) saving.value = false;
  }
}

defineExpose({ open });
</script>

<style lang="scss" scoped>
.fields {
  margin-top: 8px;
}
</style>
