'use strict';

var
	_ = require('underscore'),
	
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js')
;

module.exports = {
	ServerModuleName: 'S3Filestorage',
	HashModuleName: 's3-filestorage',
	
	AccessKey: '',
	SecretKey: '',
	Region: '',
	Host: '',
	BucketPrefix: '',
	
	/**
	 * Initializes settings from AppData object sections.
	 * 
	 * @param {Object} oAppData Object contained modules settings.
	 */
	init: function (oAppData)
	{
		var oAppDataSection = oAppData[this.ServerModuleName];
		
		if (!_.isEmpty(oAppDataSection))
		{
			this.AccessKey = Types.pString(oAppDataSection.AccessKey, this.AccessKey);
			this.SecretKey = Types.pString(oAppDataSection.SecretKey, this.SecretKey);
			this.Region = Types.pString(oAppDataSection.Region, this.Region);
			this.Host = Types.pString(oAppDataSection.Host, this.Host);
			this.BucketPrefix = Types.pString(oAppDataSection.BucketPrefix, this.BucketPrefix);
		}
	},
	
	/**
	 * Updates new settings values after saving on server.
	 * 
	 * @param {string} sAccessKey
	 * @param {string} sSecretKey
	 * @param {string} sRegion
	 * @param {string} sHost
	 * @param {string} sBucketPrefix
	 */
	update: function (sAccessKey, sSecretKey, sRegion, sHost, sBucketPrefix)
	{
		this.AccessKey = sAccessKey;
		this.SecretKey = sSecretKey;
		this.Region = sRegion;
		this.Host = sHost;
		this.BucketPrefix = sBucketPrefix;
	}
};
