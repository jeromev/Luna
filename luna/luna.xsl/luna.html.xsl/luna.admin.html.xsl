<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet
	version="1.0"
	xmlns="http://www.w3.org/1999/xhtml"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:luna="https://jeromev.github.io/Luna/ontology#" xmlns:ui="https://jeromev.github.io/Luna/render#" exclude-result-prefixes="ui" xmlns:schema="https://schema.org/"
	xmlns:dcterms="http://purl.org/dc/terms/"
	xmlns:foaf="http://xmlns.com/foaf/0.1/"
	xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
	xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
	xmlns:xsd="http://www.w3.org/2001/XMLSchema#"
	xmlns:owl="http://www.w3.org/2002/07/owl#"
	xmlns:dc="http://purl.org/dc/elements/1.1/">

	<xsl:variable name="mod_lid">mod_admin</xsl:variable>

	<xsl:include href="./luna.header.html.xsl"/>
	<xsl:include href="./luna.common_admin.html.xsl"/>

	<xsl:template name="page">
		<form method="post">
			<xsl:attribute name="action">
				<xsl:value-of select="$pageurl"/>
			</xsl:attribute>
			<xsl:call-template name="configadmin"/>
			<xsl:call-template name="csrf-input"/>
		</form>
		<xsl:if test="/rdf:RDF/luna:mod[luna:lid = 'mod_admin_groups']/luna:is_loaded = '1'">
			<xsl:call-template name="groupslist">
				<xsl:with-param name="expand">0</xsl:with-param>
			</xsl:call-template>
		</xsl:if>
		<xsl:if test="/rdf:RDF/luna:mod[luna:lid = 'mod_admin_levels']/luna:is_loaded = '1'">
			<xsl:call-template name="levelslist">
				<xsl:with-param name="expand">0</xsl:with-param>
			</xsl:call-template>
		</xsl:if>
		<xsl:if test="/rdf:RDF/luna:mod[luna:lid = 'mod_online_users']/luna:is_loaded = '1'">
			<xsl:call-template name="online_users"/>
		</xsl:if>
	</xsl:template>

	<xsl:template name="configadmin">
		<div class="box">
			<fieldset id="Config">
				<h2 class="box-handle collapsed"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Configuration']/ui:value"/></h2>
				<div class="box-content off">
					<div class="fields">
						<div class="onecol">
							<xsl:call-template name="forminput">
								<xsl:with-param name="type">checkbox</xsl:with-param>
								<xsl:with-param name="name">disable</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'disable']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'disable']/ui:value"/></xsl:with-param>
							</xsl:call-template>
						</div>
						<div class="col">
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">sitename</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'sitename']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'sitename']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">author</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'author']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'author']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">general_email</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'general_email']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'general_email']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="type">textarea</xsl:with-param>
								<xsl:with-param name="name">site_desc</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'site_desc']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'site_desc']/ui:value"/></xsl:with-param>
							</xsl:call-template>
						</div>
						<div class="col">
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">version</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'version']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'version']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">startdate</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'startdate']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'startdate']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">session_length</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'session_length']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'session_length']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="type">textarea</xsl:with-param>
								<xsl:with-param name="name">keywords</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'keywords']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'keywords']/ui:value"/></xsl:with-param>
							</xsl:call-template>
						</div>
						<div class="col">
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">timezone</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'timezone']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'timezone']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">root_module</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'root_module']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'root_module']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">langs</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'langs']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'langs']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<br />
							<xsl:call-template name="forminput">
								<xsl:with-param name="type">textarea</xsl:with-param>
								<xsl:with-param name="name">disable_txt</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'disable_txt']/ui:value"/></xsl:with-param>
								<xsl:with-param name="value"><xsl:value-of select="/rdf:RDF/ui:config[ui:lid = 'disable_txt']/ui:value"/></xsl:with-param>
							</xsl:call-template>
						</div>
					</div>
					<div class="submit">
						<input type="submit" class="submit" name="submit">
							<xsl:attribute name="value">
								<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Save']/ui:value"/>
							</xsl:attribute>
						</input>
					</div>
				</div>
			</fieldset>
		</div>
	</xsl:template>

</xsl:stylesheet>
