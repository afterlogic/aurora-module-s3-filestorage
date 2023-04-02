<template>
  <q-scroll-area class="full-height full-width">
    <div class="q-pa-lg ">
      <div class="row q-mb-md">
        <div class="col text-h5" v-t="'S3FILESTORAGE.HEADING_SETTINGS_TAB'"></div>
      </div>
      <q-card flat bordered class="card-edit-settings">
        <q-card-section>
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
    <q-inner-loading style="justify-content: flex-start;" :showing="loading || saving">
      <q-linear-progress query />
    </q-inner-loading>
  </q-scroll-area>
</template>

<script>
import errors from 'src/utils/errors'
import notification from 'src/utils/notification'
import types from 'src/utils/types'
import webApi from 'src/utils/web-api'

export default {
  name: 'S3FilestorageAdminSettingsPerTenant',

  data () {
    return {
      saving: false,
      loading: false,
      tenant: null,
      region: '',
      host: '',
      testingConnection: false
    }
  },

  computed: {
    tenantId () {
      return this.$store.getters['tenants/getCurrentTenantId']
    },

    allTenants () {
      return this.$store.getters['tenants/getTenants']
    },
  },

  watch: {
    allTenants () {
      this.populate()
    },
  },

  beforeRouteLeave (to, from, next) {
    this.$root.doBeforeRouteLeave(to, from, next)
  },

  mounted() {
    this.loading = false
    this.saving = false
    this.populate()
  },

  methods: {
    /**
     * Method is used in doBeforeRouteLeave mixin
     */
    hasChanges () {
      if (this.loading) {
        return false
      }

      const tenantCompleteData = types.pObject(this.tenant?.completeData)
      return this.region !== tenantCompleteData['S3Filestorage::Region'] || this.host !== tenantCompleteData['S3Filestorage::Host'];
    },

    /**
     * Method is used in doBeforeRouteLeave mixin,
     * do not use async methods - just simple and plain reverting of values
     * !! hasChanges method must return true after executing revertChanges method
     */
    revertChanges () {
      const tenantCompleteData = types.pObject(this.tenant?.completeData)
      this.region = tenantCompleteData['S3Filestorage::Region']
      this.host = tenantCompleteData['S3Filestorage::Host']
    },

    populate () {
      const tenant = this.$store.getters['tenants/getTenant'](this.tenantId)
      if (tenant) {
        if (tenant.completeData['S3Filestorage::Region'] !== undefined) {
          this.tenant = tenant
          this.region = tenant.completeData['S3Filestorage::Region']
          this.host = tenant.completeData['S3Filestorage::Host']
        } else {
          this.getSettings()
        }
      }
    },

    save () {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          TenantId: this.tenantId,
          Region: this.region,
          Host: this.host,
        }
        webApi.sendRequest({
          moduleName: 'S3Filestorage',
          methodName: 'UpdateS3Settings',
          parameters
        }).then(result => {
          this.saving = false
          if (result) {
            const data = {
              'S3Filestorage::Region': parameters.Region,
              'S3Filestorage::Host': parameters.Host,
            }
            this.$store.commit('tenants/setTenantCompleteData', { id: this.tenantId, data })
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

    getSettings () {
      this.loading = true
      const parameters = {
        TenantId: this.tenantId,
      }
      webApi.sendRequest({
        moduleName: 'S3Filestorage',
        methodName: 'GetSettings',
        parameters
      }).then(result => {
        this.loading = false
        if (result) {
          const data = {
            'S3Filestorage::Region': types.pString(result.Region),
            'S3Filestorage::Host': types.pString(result.Host),
          }
          this.$store.commit('tenants/setTenantCompleteData', { id: this.tenantId, data })
        }
      }, response => {
        notification.showError(errors.getTextFromResponse(response))
      })
    },

    testConnection() {
      if (!this.testingConnection) {
        this.testingConnection = true
        const parameters = {
          Region: this.region,
          Host: this.host,
          TenantId: this.tenantId
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

<style scoped>

</style>
