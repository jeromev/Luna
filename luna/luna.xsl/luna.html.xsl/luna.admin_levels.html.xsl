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

	<xsl:variable name="mod_lid">mod_admin_levels</xsl:variable>

	<xsl:include href="./luna.header.html.xsl"/>
	<xsl:include href="./luna.common_admin.html.xsl"/>

	<xsl:variable name="modify_item_nid"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'modify_item_nid']/ui:value"/></xsl:variable>

	<xsl:template name="page">
		<xsl:if test="/rdf:RDF/luna:mod[luna:lid = $mod_lid]/luna:is_loaded = '1'">
			<xsl:if test="$modify_item_nid = ''">
				<div class="box">
					<form method="post" id="Addlevel">
						<xsl:attribute name="action"><xsl:value-of select="$pageurl"/></xsl:attribute>
						<fieldset>
							<h2>
								<xsl:attribute name="class">
									<xsl:text>box-handle</xsl:text>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:message[ui:code = 'warning']">
											<xsl:text> expanded</xsl:text>
										</xsl:when>
										<xsl:otherwise>
											<xsl:text> collapsed</xsl:text>
										</xsl:otherwise>
									</xsl:choose>
								</xsl:attribute>
								<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Add a level']/ui:value"/>
							</h2>
							<div>
								<xsl:attribute name="class">
									<xsl:text>box-content</xsl:text>
									<xsl:if test="not(/rdf:RDF/ui:message[ui:code = 'warning'])">
										<xsl:text> off</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<div class="fields">
									<div class="onecol">
										<xsl:call-template name="forminput">
											<xsl:with-param name="type">checkbox</xsl:with-param>
											<xsl:with-param name="name">add_level_is_inactive</xsl:with-param>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Deactivate']/ui:value"/></xsl:with-param>
										</xsl:call-template>
										<br />
									</div>
									<div class="col">
										<xsl:call-template name="forminput">
											<xsl:with-param name="name">add_level_lid</xsl:with-param>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/></xsl:with-param>
										</xsl:call-template>
										<br />
										<br/>
									</div>
									<div class="col">
										<xsl:call-template name="forminput">
											<xsl:with-param name="name">add_level_groups</xsl:with-param>
											<xsl:with-param name="type">select</xsl:with-param>
											<xsl:with-param name="foreach" select="/rdf:RDF/luna:group"/>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Groups admitted to this level']/ui:value"/></xsl:with-param>
											<xsl:with-param name="multiple">yes</xsl:with-param>
											<xsl:with-param name="required" select="/rdf:RDF/luna:group[luna:lid = 'group_admin']/schema:identifier"/>
											<xsl:with-param name="size">
												<xsl:value-of select="count(/rdf:RDF/luna:group)"/>
											</xsl:with-param>
										</xsl:call-template>
										<br />
									</div>
								</div>
								<div class="submit">
									<input type="hidden" name="mode" value="add"/>
									<input type="submit" class="submit" name="submit">
										<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Add']/ui:value"/></xsl:attribute>
									</input>
								</div>
							</div>
						</fieldset>
						<xsl:call-template name="csrf-input"/>
					</form>
				</div>
				<xsl:call-template name="levelslist"/>
			</xsl:if>
			<xsl:if test="not($modify_item_nid = '')">
			<div class="box">
				<form method="post" id="Modifylevel">
					<xsl:attribute name="action"><xsl:value-of select="$pageurl"/></xsl:attribute>
					<fieldset>
						<h2 class="box-handle expanded">
							<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modify the level']/ui:value"/>
							<xsl:text> </xsl:text>
							<em><xsl:value-of select="/rdf:RDF/luna:level[schema:identifier = $modify_item_nid]/schema:name"/></em>
						</h2>
						<div class="box-content">
							<div class="fields">
								<div class="onecol">
									<xsl:call-template name="forminput">
										<xsl:with-param name="type">checkbox</xsl:with-param>
										<xsl:with-param name="name">modify_level_is_inactive</xsl:with-param>
										<xsl:with-param name="label" select="/rdf:RDF/ui:vocabulary[ui:lid = 'Deactivate']/ui:value"/>
										<xsl:with-param name="default-value">
											<xsl:choose>
												<xsl:when test="/rdf:RDF/luna:level[schema:identifier = $modify_item_nid]/luna:isActive = '1'">
													<xsl:text>0</xsl:text>
												</xsl:when>
												<xsl:otherwise>
													<xsl:text>1</xsl:text>
												</xsl:otherwise>
											</xsl:choose>
										</xsl:with-param>
									</xsl:call-template>
									<br />
								</div>
								<div class="col">
									<xsl:call-template name="forminput">
										<xsl:with-param name="name">modify_level_lid</xsl:with-param>
										<xsl:with-param name="label" select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/>
										<xsl:with-param name="default-value" select="/rdf:RDF/luna:level[schema:identifier = $modify_item_nid]/luna:lid"/>
									</xsl:call-template>
									<br />
								</div>
								<div class="col">
									<xsl:call-template name="forminput">
										<xsl:with-param name="name">modify_level_groups</xsl:with-param>
										<xsl:with-param name="type">select</xsl:with-param>
										<xsl:with-param name="foreach" select="/rdf:RDF/luna:group"/>
										<xsl:with-param name="label" select="/rdf:RDF/ui:vocabulary[ui:lid = 'Groups admitted to this level']/ui:value"/>
										<xsl:with-param name="multiple">yes</xsl:with-param>
										<xsl:with-param name="size" select="count(/rdf:RDF/luna:group)"/>
										<xsl:with-param name="default-value" select="/rdf:RDF/luna:level[schema:identifier = $modify_item_nid]/luna:group"/>
									</xsl:call-template>
									<br />
								</div>
							</div>
							<div class="submit">
								<input type="hidden" name="mode" value="modify"/>
								<input type="hidden" name="modify_item_nid">
									<xsl:attribute name="value"><xsl:value-of select="$modify_item_nid"/></xsl:attribute>
								</input>
								<input type="hidden" name="level_nid">
									<xsl:attribute name="value"><xsl:value-of select="$modify_item_nid"/></xsl:attribute>
								</input>
								<input type="submit" class="submit" name="submit">
									<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modify']/ui:value"/></xsl:attribute>
								</input>
								<input type="submit" class="submit warning" name="submit">
									<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Delete']/ui:value"/></xsl:attribute>
									<xsl:attribute name="data-confirm">

										<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Are you sure you want to delete this level?']/ui:value"/>

									</xsl:attribute>
								</input>
							</div>
						</div>
					</fieldset>
					<xsl:call-template name="csrf-input"/>
				</form>
			</div>
			<xsl:call-template name="levelslist">
				<xsl:with-param name="expand">0</xsl:with-param>
			</xsl:call-template>
		</xsl:if>
		</xsl:if>
	</xsl:template>

</xsl:stylesheet>
