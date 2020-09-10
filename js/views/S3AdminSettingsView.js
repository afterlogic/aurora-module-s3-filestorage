'use strict';

var
	_ = require('underscore'),
	ko = require('knockout'),
	
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js'),
	
	Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),
	ModulesManager = require('%PathToCoreWebclientModule%/js/ModulesManager.js'),
	
	CAbstractSettingsFormView = ModulesManager.run('AdminPanelWebclient', 'getAbstractSettingsFormViewClass'),
	
	Settings = require('modules/%ModuleName%/js/Settings.js')
;

/**
* @constructor
*/
function CS3AdminSettingsView()
{
	CAbstractSettingsFormView.call(this, Settings.ServerModuleName);
	
	this.isSuperAdmin = ko.observable(false);
	this.iTenantId = 0;
	
	/* Editable fields */
	this.accessKey = ko.observable(Settings.AccessKey);
	this.secretKey = ko.observable(Settings.SecretKey);
	this.region = ko.observable(Settings.Region);
	this.host = ko.observable(Settings.Host);
	this.bucketPrefix = ko.observable(Settings.BucketPrefix);
	/*-- Editable fields */
}

_.extendOwn(CS3AdminSettingsView.prototype, CAbstractSettingsFormView.prototype);

CS3AdminSettingsView.prototype.ViewTemplate = '%ModuleName%_S3AdminSettingsView';

CS3AdminSettingsView.prototype.getCurrentValues = function()
{
	return [
		this.accessKey(),
		this.secretKey(),
		this.region(),
		this.host(),
		this.bucketPrefix()
	];
};

CS3AdminSettingsView.prototype.revertGlobalValues = function()
{
	this.accessKey(Settings.AccessKey);
	this.secretKey(Settings.SecretKey);
	this.region(Settings.Region);
	this.host(Settings.Host);
	this.bucketPrefix(Settings.BucketPrefix);
};

CS3AdminSettingsView.prototype.clearFields = function()
{
	this.accessKey('');
	this.secretKey('');
	this.region('');
	this.host('');
	this.bucketPrefix('');
};

/**
 * Sends a request to the server to save the settings.
 */
CS3AdminSettingsView.prototype.save = function ()
{
	if (!_.isFunction(this.validateBeforeSave) || this.validateBeforeSave())
	{
		this.isSaving(true);
		Ajax.send(this.sServerModule, 'UpdateS3Settings', this.getParametersForSave(), this.onResponse, this);
	}
};

CS3AdminSettingsView.prototype.getParametersForSave = function ()
{
	var oParameters = {
		'AccessKey': this.accessKey(),
		'SecretKey': this.secretKey(),
		'Region': this.region(),
		'Host': this.host(),
		'BucketPrefix': this.bucketPrefix()
	};
	if (Types.isPositiveNumber(this.iTenantId)) // S3 settings tab is shown for particular tenant
	{
		oParameters.TenantId = this.iTenantId;
	}
	return oParameters;
};

/**
 * Applies saved values to the Settings object.
 * 
 * @param {Object} oParameters Parameters which were saved on the server side.
 */
CS3AdminSettingsView.prototype.applySavedValues = function (oParameters)
{
	if (this.isSuperAdmin())
	{
		Settings.update(oParameters.AccessKey, oParameters.SecretKey, oParameters.Region, oParameters.Host, oParameters.BucketPrefix);
	}
};

CS3AdminSettingsView.prototype.setAccessLevel = function (sEntityType, iEntityId)
{
	this.iTenantId = (sEntityType === 'Tenant') ? iEntityId : 0;
	this.visible(sEntityType === '' || sEntityType === 'Tenant');
	this.isSuperAdmin(sEntityType === '');
};


CS3AdminSettingsView.prototype.onRouteChild = function (aParams)
{
	this.requestPerTenantSettings();
};

CS3AdminSettingsView.prototype.requestPerTenantSettings = function ()
{
	if (Types.isPositiveNumber(this.iTenantId))
	{
		this.clearFields();
		Ajax.send(Settings.ServerModuleName, 'GetSettings', { 'TenantId': this.iTenantId }, function (oResponse) {
			if (oResponse.Result)
			{
				this.region(oResponse.Result.Region);
				this.updateSavedState();
			}
		}, this);
	}
	else
	{
		this.revertGlobalValues();
	}
};

module.exports = new CS3AdminSettingsView();
