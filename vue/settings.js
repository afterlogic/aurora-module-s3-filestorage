import _ from 'lodash'

import typesUtils from 'src/utils/types'

class S3FilestorageSettings {
  constructor (appData) {
    const s3Filestorage = typesUtils.pObject(appData.S3Filestorage)
    if (!_.isEmpty(s3Filestorage)) {
      this.accessKey = typesUtils.pString(s3Filestorage.AccessKey)
      this.secretKey = typesUtils.pString(s3Filestorage.SecretKey)
      this.region = typesUtils.pString(s3Filestorage.Region)
      this.host = typesUtils.pString(s3Filestorage.Host)
      this.bucketPrefix = typesUtils.pString(s3Filestorage.BucketPrefix)
    }
  }

  saveS3FilestorageSettings ({ accessKey, secretKey, region, host, bucketPrefix }) {
    this.accessKey = accessKey
    this.secretKey = secretKey
    this.region = region
    this.host = host
    this.bucketPrefix = bucketPrefix
  }
}

let settings = null

export default {
  init (appData) {
    settings = new S3FilestorageSettings(appData)
  },
  saveS3FilestorageSettings (data) {
    settings.saveS3FilestorageSettings(data)
  },
  getS3FilestorageSettings () {
    return {
      accessKey: settings.accessKey,
      secretKey: settings.secretKey,
      region: settings.region,
      host: settings.host,
      bucketPrefix: settings.bucketPrefix,
    }
  },
}
