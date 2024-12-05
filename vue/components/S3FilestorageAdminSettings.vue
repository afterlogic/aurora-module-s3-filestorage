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
              <q-input outlined dense bg-color="white" v-model="accessKey" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_SECRET_KEY'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="secretKey" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_REGION'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="region" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" />
            <div class="col-8">
              <q-item-label caption>
                {{ $t('S3FILESTORAGE.INFO_REGION') }}
              </q-item-label>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_HOST'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="host" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" />
            <div class="col-8">
              <q-item-label caption>
                {{ $t('S3FILESTORAGE.INFO_HOST') }}
              </q-item-label>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'S3FILESTORAGE.LABEL_BUCKET_PREFIX'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="bucketPrefix" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-xl">
            <div class="col-2 q-my-sm"></div>
            <div class="col-5">
              <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary"
                      :label="$t('S3FILESTORAGE.BUTTON_TEST_CONNECTION')" @click="testConnection">
              </q-btn>
            </div>
          </div>
        </q-card-section>
      </q-card>
      <div class="q-pt-md text-right">
        <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary" @click="save"
               :label="$t('COREWEBCLIENT.ACTION_SAVE')">
        </q-btn>
      </div>
    </div>
    <q-inner-loading style="justify-content: flex-start;" :showing="saving">
      <q-linear-progress query />
    </q-inner-loading>
  </q-scroll-area>
</template>

<script>
import errors from 'src/utils/errors'
import notification from 'src/utils/notification'
import webApi from 'src/utils/web-api'

import settings from '../settings'

export default {
  name: 'S3FilestorageAdminSettings',

  mounted () {
    this.populate()
  },

  beforeRouteLeave(to, from, next) {
    this.$root.doBeforeRouteLeave(to, from, next)
  },

  data () {
    return {
      saving: false,
      accessKey: '',
      secretKey: '',
      region: '',
      host: '',
      bucketPrefix: '',
      testingConnection: false
    }
  },
  methods: {
    /**
     * Method is used in doBeforeRouteLeave mixin
     */
    hasChanges() {
      const data = settings.getS3FilestorageSettings()
      return this.accessKey !== data.accessKey ||
          this.secretKey !== data.secretKey ||
          this.region !== data.region ||
          this.host !== data.host ||
          this.bucketPrefix !== data.bucketPrefix
    },

    /**
     * Method is used in doBeforeRouteLeave mixin,
     * do not use async methods - just simple and plain reverting of values
     * !! hasChanges method must return true after executing revertChanges method
     */
    revertChanges () {
      this.populate()
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
    },

    testConnection() {
      if (!this.testingConnection) {
        this.testingConnection = true
        const parameters = {
          AccessKey: this.accessKey,
          SecretKey: this.secretKey,
          Region: this.region,
          Host: this.host
        }
        webApi.sendRequest({
          moduleName: 'S3Filestorage',
          methodName: 'TestConnection',
          parameters,
        }).then(result => {
          this.testingConnection = false
          if (result === true) {
            notification.showReport(this.$t('S3FILESTORAGE.REPORT_CONNECT_SUCCESSFUL'))
          } else {
            notification.showError(this.$t('S3FILESTORAGE.ERROR_CONNECT_FAILED'))
          }
        }, response => {
          this.testingConnection = false
          notification.showError(errors.getTextFromResponse(response, this.$t('S3FILESTORAGE.ERROR_CONNECT_FAILED')))
        })
      }
    }
  }
}
</script>
