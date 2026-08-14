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

	<xsl:variable name="mod_lid">mod_journal</xsl:variable>

	<xsl:include href="./luna.header.html.xsl"/>
	<xsl:include href="./luna.common_admin.html.xsl"/>

	<xsl:variable name="log_id"><xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'log_id']/ui:value"/></xsl:variable>

	<xsl:template name="page">
		<xsl:if test="$log_id = ''">
			<div id="LogsList" class="box">
				<h2><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'List of the log entries']/ui:value"/></h2>
				<div class="boxtable">
					<table class="zebra">
						<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'List of the log entries']/ui:value"/></caption>
						<thead>
							<tr>
								<td colspan="4">
									<xsl:call-template name="pager"/>
								</td>
							</tr>
							<tr>
								<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Message']/ui:value"/></th>
								<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Type']/ui:value"/></th>
								<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Date']/ui:value"/></th>
								<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'User']/ui:value"/></th>
							</tr>
						</thead>
						<tbody>
							<xsl:for-each select="/rdf:RDF/ui:log">
								<tr>
									<xsl:attribute name="data-href">

										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
											<xsl:with-param name="options">
												<xsl:text>log_id=</xsl:text><xsl:value-of select="ui:lid"/>
											</xsl:with-param>
										</xsl:call-template>
										<xsl:call-template name="onchange4"/>

									</xsl:attribute>
									<xsl:attribute name="class">
										<xsl:text>active </xsl:text>
										<xsl:value-of select="ui:code"/>
									</xsl:attribute>
									<td>
										<a>
											<xsl:attribute name="href">
												<xsl:call-template name="link">
													<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
													<xsl:with-param name="options">
														<xsl:text>log_id=</xsl:text><xsl:value-of select="ui:lid"/>
													</xsl:with-param>
												</xsl:call-template>
											</xsl:attribute>
											<xsl:attribute name="aria-label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Message']/ui:value"/><xsl:text> </xsl:text><xsl:value-of select="ui:lid"/></xsl:attribute>
											<xsl:call-template name="cutstring">
												<xsl:with-param name="string">
														<xsl:value-of select="ui:message"/>
												</xsl:with-param>
												<xsl:with-param name="length" select="60"/>
											</xsl:call-template>
										</a>
									</td>
									<td><xsl:value-of select="ui:code"/></td>
									<td class="nowrap"><xsl:value-of select="ui:date"/></td>
									<td class="nowrap"><xsl:value-of select="ui:user-name"/></td>
								</tr>
							</xsl:for-each>
						</tbody>
					</table>
				</div>
			</div>
			<form method="post" id="PurgeLogs">
				<xsl:call-template name="csrf-input"/>
				<input type="hidden" name="purgelogs" value="1"/>
				<button type="submit">Purge the log</button>
			</form>
		</xsl:if>
		<xsl:if test="not($log_id = '')">
			<div id="AnalyseLog" class="box">
				<h2><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Log entry analyse']/ui:value"/></h2>
				<dl class="analyse">
					<xsl:for-each select="/rdf:RDF/ui:log/*">
						<xsl:variable name="name"><xsl:value-of select="local-name()"/></xsl:variable>
						<dt>
							<xsl:choose>
								<xsl:when test="/rdf:RDF/ui:vocabulary[ui:lid = $name]/ui:value">
									<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = $name]/ui:value"/>
								</xsl:when>
								<xsl:otherwise>
									<xsl:value-of select="$name"/>
								</xsl:otherwise>
							</xsl:choose>
						</dt>
						<dd>
							<pre>
								<xsl:value-of select="."/>
							</pre>
						</dd>
					</xsl:for-each>
				</dl>
				<!--h3><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Server']/ui:value"/></h3>
				<dl class="analyse">
					<xsl:for-each select="/rdf:RDF/ui:logserver/*">
						<xsl:variable name="name"><xsl:value-of select="name()"/></xsl:variable>
						<dt>
							<xsl:choose>
								<xsl:when test="/rdf:RDF/ui:vocabulary[ui:lid = $name]/ui:value">
									<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = $name]/ui:value"/>
								</xsl:when>
								<xsl:otherwise>
									<xsl:value-of select="$name"/>
								</xsl:otherwise>
							</xsl:choose>
						</dt>
						<dd class="autoscroll"><xsl:value-of select="."/></dd>
					</xsl:for-each>
				</dl-->
			</div>
		</xsl:if>
	</xsl:template>

</xsl:stylesheet>
