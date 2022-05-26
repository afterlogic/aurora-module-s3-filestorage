import settings from './settings'

import S3FilestorageAdminSettingsPerTenant from './components/S3FilestorageAdminSettingsPerTenant'

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
        tabTitle: 'S3FILESTORAGE.LABEL_SETTINGS_TAB',
        tabRouteChildren: [
          { path: 's3-filestorage', component: () => import('./components/S3FilestorageAdminSettings') },
        ],
      },
    ]
  },

  getAdminTenantTabs () {
    return [
      {
        tabName: 's3-filestorage',
        tabTitle: 'S3FILESTORAGE.LABEL_SETTINGS_TAB',
        tabRouteChildren: [
          { path: 'id/:id/chat', component: S3FilestorageAdminSettingsPerTenant },
          { path: 'search/:search/id/:id/chat', component: S3FilestorageAdminSettingsPerTenant },
          { path: 'page/:page/id/:id/chat', component: S3FilestorageAdminSettingsPerTenant },
          { path: 'search/:search/page/:page/id/:id/chat', component: S3FilestorageAdminSettingsPerTenant },
        ],
      }
    ]
  },
}
