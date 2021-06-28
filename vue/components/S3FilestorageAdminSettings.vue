<template>
  <q-scroll-area class="full-height full-width">
    <div class="q-pa-lg ">
      <div class="row q-mb-md">
        <div class="col text-h5" v-t="'S3FILESTORAGE.HEADING_SETTINGS_TAB'"></div>
      </div>
      <q-card flat bordered class="card-edit-settings">
        <q-card-section>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_ACCESS_KEY'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="accessKey" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_SECRET_KEY'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="secretKey" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_REGION'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="region" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_HOST'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="host" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_BUCKET_PREFIX'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="bucketPrefix" @keyup.enter="save"/>
            </div>
          </div>
        </q-card-section>
      </q-card>
      <div class="q-pt-md text-right">
        <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary" @click="save"
               :label="saving ? $t('COREWEBCLIENT.ACTION_SAVE_IN_PROGRESS') : $t('COREWEBCLIENT.ACTION_SAVE')">
        </q-btn>
      </div>
    </div>
    <UnsavedChangesDialog ref="unsavedChangesDialog"/>
  </q-scroll-area>
</template>

<script>
import settings from '../../../S3Filestorage/vue/settings'
import UnsavedChangesDialog from 'src/components/UnsavedChangesDialog'
import webApi from 'src/utils/web-api'
import notification from 'src/utils/notification'
import errors from 'src/utils/errors'
import _ from 'lodash'

export default {
  name: 'S3FilestorageAdminSettings',
  components: {
    UnsavedChangesDialog
  },
  mounted () {
    this.populate()
  },
  beforeRouteLeave(to, from, next) {
    if (this.hasChanges() && _.isFunction(this?.$refs?.unsavedChangesDialog?.openConfirmDiscardChangesDialog)) {
      this.$refs.unsavedChangesDialog.openConfirmDiscardChangesDialog(next)
    } else {
      next()
    }
  },
  data () {
    return {
      saving: false,
      accessKey: '',
      secretKey: '',
      region: '',
      host: '',
      bucketPrefix: ''
    }
  },
  methods: {
    hasChanges() {
      const data = settings.getS3FilestorageSettings()
      return this.accessKey !== data.accessKey ||
          this.secretKey !== data.secretKey ||
          this.region !== data.region ||
          this.host !== data.host ||
          this.bucketPrefix !== data.bucketPrefix
    },
    save () {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          AccessKey: this.accessKey,
          SecretKey: this.secretKey,
          Region: this.region,
          Host: this.host,
          BucketPrefix: this.bucketPrefix
        }
        webApi.sendRequest({
          moduleName: 'S3Filestorage',
          methodName: 'UpdateS3Settings',
          parameters,
        }).then(result => {
          this.saving = false
          if (result === true) {
            settings.saveS3FilestorageSettings({
              accessKey: this.accessKey,
              secretKey: this.secretKey,
              region: this.region,
              host: this.host,
              bucketPrefix: this.bucketPrefix
            })
            this.populate()
            notification.showReport(this.$t('COREWEBCLIENT.REPORT_SETTINGS_UPDATE_SUCCESS'))
          } else {
            notification.showError(this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED'))
          }
        }, response => {
          this.saving = false
          notification.showError(errors.getTextFromResponse(response, this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED')))
        })
      }
    },
    populate () {
      const data = settings.getS3FilestorageSettings()
      this.accessKey = data.accessKey
      this.secretKey = data.secretKey
      this.region = data.region
      this.host = data.host
      this.bucketPrefix = data.bucketPrefix
    }
  }
}
</script>

<style scoped>

</style>
