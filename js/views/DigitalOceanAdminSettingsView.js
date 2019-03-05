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
function CDigitalOceanAdminSettingsView()
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

_.extendOwn(CDigitalOceanAdminSettingsView.prototype, CAbstractSettingsFormView.prototype);

CDigitalOceanAdminSettingsView.prototype.ViewTemplate = '%ModuleName%_DigitalOceanAdminSettingsView';

CDigitalOceanAdminSettingsView.prototype.getCurrentValues = function()
{
	return [
		this.accessKey(),
		this.secretKey(),
		this.region(),
		this.host(),
		this.bucketPrefix()
	];
};

CDigitalOceanAdminSettingsView.prototype.revertGlobalValues = function()
{
	this.accessKey(Settings.AccessKey);
	this.secretKey(Settings.SecretKey);
	this.region(Settings.Region);
	this.host(Settings.Host);
	this.bucketPrefix(Settings.BucketPrefix);
};

CDigitalOceanAdminSettingsView.prototype.clearFields = function()
{
	this.accessKey('');
	this.secretKey('');
	this.region('');
	this.host('');
	this.bucketPrefix('');
};

CDigitalOceanAdminSettingsView.prototype.getParametersForSave = function ()
{
	return {
		'AccessKey': this.accessKey(),
		'SecretKey': this.secretKey(),
		'Region': this.region(),
		'Host': this.host(),
		'BucketPrefix': this.bucketPrefix()
	};
};

/**
 * Applies saved values to the Settings object.
 * 
 * @param {Object} oParameters Parameters which were saved on the server side.
 */
CDigitalOceanAdminSettingsView.prototype.applySavedValues = function (oParameters)
{
	if (this.isSuperAdmin())
	{
		Settings.update(oParameters.AccessKey, oParameters.SecretKey, oParameters.Region, oParameters.Host, oParameters.BucketPrefix);
	}
};

CDigitalOceanAdminSettingsView.prototype.setAccessLevel = function (sEntityType, iEntityId)
{
	this.iTenantId = (sEntityType === 'Tenant') ? iEntityId : 0;
	this.visible(sEntityType === '' || sEntityType === 'Tenant');
	this.isSuperAdmin(sEntityType === '');
};


CDigitalOceanAdminSettingsView.prototype.onRouteChild = function (aParams)
{
	this.requestPerTenantSettings();
};

CDigitalOceanAdminSettingsView.prototype.requestPerTenantSettings = function ()
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

module.exports = new CDigitalOceanAdminSettingsView();
