import settings from './settings'
import store from 'src/store'

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

  // TODO: removed tenant setting form until it alllows to edit all settings, not only region and host
  // getAdminTenantTabs () {
  //   const isUserSuperAdmin = store.getters['user/isUserSuperAdmin']
  //   if (isUserSuperAdmin) {
  //     return [
  //       {
  //         tabName: 's3-filestorage',
  //         tabTitle: 'S3FILESTORAGE.LABEL_SETTINGS_TAB',
  //         tabRouteChildren: [
  //           { path: 'id/:id/s3-filestorage', component: S3FilestorageAdminSettingsPerTenant },
  //           { path: 'search/:search/id/:id/s3-filestorage', component: S3FilestorageAdminSettingsPerTenant },
  //           { path: 'page/:page/id/:id/s3-filestorage', component: S3FilestorageAdminSettingsPerTenant },
  //           { path: 'search/:search/page/:page/id/:id/s3-filestorage', component: S3FilestorageAdminSettingsPerTenant },
  //         ],
  //       }
  //     ]
  //   } else {
  //     return []
  //   }
  // },
}
