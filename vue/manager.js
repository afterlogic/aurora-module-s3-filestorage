import settings from '../../S3Filestorage/vue/settings'

export default {
  moduleName: 'S3Filestorage',

  requiredModules: [],

  init (appData) {
    settings.init(appData)
  },

  getAdminSystemTabs () {
    return [
      {
        tabName: 's3-filestorage',
        title: 'S3FILESTORAGE.LABEL_SETTINGS_TAB',
        component () {
          return import('./components/S3FilestorageAdminSettings')
        },
      },
    ]
  },
  getAdminTenantTabs () {
    return [
      {
        tabName: 's3-filestorage',
        paths: [
          'id/:id/s3-filestorage',
          'search/:search/id/:id/s3-filestorage',
          'page/:page/id/:id/s3-filestorage',
          'search/:search/page/:page/id/:id/s3-filestorage',
        ],
        title: 'S3FILESTORAGE.LABEL_SETTINGS_TAB',
        component () {
          return import('./components/S3FilestorageAdminSettingsPerTenant')
        }
      }
    ]
  },
}
