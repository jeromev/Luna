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

	<xsl:variable name="mod_lid">mod_log</xsl:variable>

	<xsl:include href="./luna.header.html.xsl"/>

	<xsl:template name="page">
		<form method="post">
			<xsl:attribute name="action"><xsl:value-of select="$pageurl"/></xsl:attribute>
			<div class="box">
				<fieldset id="Login">
					<h2 class="box-handle expanded"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Connection form']/ui:value"/></h2>
					<div class="box-content">
						<div class="fields">
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">email</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Email']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<xsl:call-template name="forminput">
								<xsl:with-param name="name">password</xsl:with-param>
								<xsl:with-param name="type">password</xsl:with-param>
								<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Password']/ui:value"/></xsl:with-param>
							</xsl:call-template>
							<input type="hidden" name="last_url">
								<xsl:attribute name="value">
									<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'last_url']/ui:value"/>
								</xsl:attribute>
							</input>
						</div>
						<div class="submit">
							<input type="submit" class="submit" name="submit"/>
						</div>
					</div>
				</fieldset>
			</div>
			<xsl:call-template name="csrf-input"/>
		</form>
	</xsl:template>

</xsl:stylesheet>
