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

	<xsl:template name="online_users">
		<xsl:param name="expand">1</xsl:param>
		<div id="OnlineUsers" class="box">
			<xsl:variable name="mod_nid"><xsl:value-of select="/rdf:RDF/luna:mod[luna:lid = 'mod_online_users']/schema:identifier"/></xsl:variable>
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/luna:mod[schema:identifier = $mod_nid]/schema:name"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/luna:mod[schema:identifier = $mod_nid]/schema:name"/></caption>
					<thead>
						<tr>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'firstname']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'lastname']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'email']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'IP']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'session_url']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'last_time']/ui:value"/></th>
						</tr>
					</thead>
					<tbody>
						<xsl:variable name="admin_users_url" select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_users']/luna:alias"/>
						<xsl:for-each select="/rdf:RDF/foaf:Person">
							<xsl:variable name="user_lid" select="luna:lid"/>
							<tr>
								<td><xsl:value-of select="foaf:firstName"/></td>
								<td><xsl:value-of select="foaf:surName"/></td>
								<td>
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_users']/luna:alias"/>
												<xsl:with-param name="options">
													<xsl:text>user_lid=</xsl:text>
													<xsl:value-of select="luna:lid"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:call-template name="cutstring">
											<xsl:with-param name="string">
												<xsl:call-template name="cutstring">
													<xsl:with-param name="string"><xsl:value-of select="foaf:mbox/@rdf:resource"/></xsl:with-param>
													<xsl:with-param name="before" select="1"/>
													<xsl:with-param name="length" select="8"/>
												</xsl:call-template>
											</xsl:with-param>
										</xsl:call-template>
									</a>
								</td>
								<td><xsl:value-of select="luna:ip"/></td>
								<td class="nowrap">
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="luna:url"/>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:attribute name="title">
											<xsl:value-of select="luna:url"/>
										</xsl:attribute>
										<xsl:text>/</xsl:text>
										<xsl:call-template name="cutstring">
											<xsl:with-param name="string"><xsl:value-of select="luna:url"/></xsl:with-param>
										</xsl:call-template>
									</a>
								</td>
								<td class="nowrap"><xsl:value-of select="luna:last-visit"/></td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="modslist">
		<xsl:param name="expand">1</xsl:param>
		<div id="ModsList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modules']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modules']/ui:value"/></caption>
					<thead>
						<tr>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'name']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Access level']/ui:value"/></th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/luna:mod">
							<xsl:sort select="schema:name"/>
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
										<xsl:with-param name="options">
											<xsl:text>mod_nid=</xsl:text><xsl:value-of select="schema:identifier"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange3"/>

								</xsl:attribute>
								<td>
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
												<xsl:with-param name="options">
													<xsl:text>mod_nid=</xsl:text><xsl:value-of select="schema:identifier"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:value-of select="schema:name"/>
									</a>
								</td>
								<td>
									<xsl:variable name="level-resource" select="luna:level/@rdf:resource"/>
									<xsl:value-of select="/rdf:RDF/luna:level[@rdf:about = $level-resource]/schema:name"/>
								</td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="textslist">
		<xsl:param name="expand">1</xsl:param>
		<xsl:param name="modpagealias">
			<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'edit_texts']/luna:alias"/>
		</xsl:param>
		<div id="TextsList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Texts']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Texts']/ui:value"/></caption>
					<thead>
						<tr>
							<td colspan="5">
								<xsl:call-template name="pager"/>
							</td>
						</tr>
						<tr>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=lid&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'lid'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=title&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'title'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'title']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'title']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=lang&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'lang'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'lang']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'lang']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=last_time&amp;order_dir=DESC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'last_time'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'modified']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'modified']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/schema:Article">
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="$modpagealias"/>
										<xsl:with-param name="options">
											<xsl:text>text_nid=</xsl:text>
											<xsl:value-of select="schema:identifier"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange4"/>

								</xsl:attribute>
								<td>
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="$modpagealias"/>
												<xsl:with-param name="options">
													<xsl:text>text_nid=</xsl:text>
													<xsl:value-of select="schema:identifier"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:value-of select="luna:lid"/>
									</a>
								</td>
								<td><xsl:value-of select="schema:name"/></td>
								<td><xsl:value-of select="schema:name/@xml:lang"/></td>
								<td class="nowrap"><xsl:value-of select="luna:save_time"/></td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="userslist">
		<xsl:param name="expand">1</xsl:param>
		<xsl:param name="modpagealias">
			<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_users']/luna:alias"/>
		</xsl:param>
		<div id="UsersList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Users']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Users']/ui:value"/></caption>
					<thead>
						<tr>
							<td colspan="5">
								<xsl:call-template name="pager"/>
							</td>
						</tr>
						<tr>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=firstname&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'firstname'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'firstname']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'firstname']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=lastname&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'lastname'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'lastname']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'lastname']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=email&amp;order_dir=ASC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'email'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'email']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'email']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=regis_time&amp;order_dir=DESC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'regis_time'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'regis_time']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'regis_time']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
							<th>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link">
											<xsl:with-param name="alias" select="$modpagealias"/>
											<xsl:with-param name="options">
												<xsl:text>order_by=last_time&amp;order_dir=DESC&amp;start=0</xsl:text>
											</xsl:with-param>
										</xsl:call-template>
									</xsl:attribute>
									<xsl:choose>
										<xsl:when test="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value = 'last_time'">
											<strong><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'last_time']/ui:value"/></strong>
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'last_time']/ui:value"/>
										</xsl:otherwise>
									</xsl:choose>
								</a>
							</th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/foaf:Person">
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
										<xsl:with-param name="options">
											<xsl:text>user_lid=</xsl:text>
											<xsl:value-of select="luna:lid"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange4"/>

								</xsl:attribute>
								<td><xsl:value-of select="foaf:firstName"/></td>
								<td><xsl:value-of select="foaf:surName"/></td>
								<td>
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
												<xsl:with-param name="options">
													<xsl:text>user_lid=</xsl:text>
													<xsl:value-of select="luna:lid"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:call-template name="cutstring">
											<xsl:with-param name="string">
												<xsl:value-of select="foaf:mbox/@rdf:resource"/>
											</xsl:with-param>
											<xsl:with-param name="length">8</xsl:with-param>
											<xsl:with-param name="before">1</xsl:with-param>
										</xsl:call-template>
									</a>
								</td>
								<td class="nowrap"><xsl:value-of select="luna:registration-date"/></td>
								<td class="nowrap"><xsl:value-of select="luna:last-visit"/></td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="pageslist">
		<xsl:param name="expand">1</xsl:param>
		<xsl:param name="modpagealias">
			<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_pages']/luna:alias"/>
		</xsl:param>
		<div id="PagesList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'"><xsl:text> expanded</xsl:text></xsl:when>
						<xsl:otherwise><xsl:text> collapsed</xsl:text></xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Pages']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'"><xsl:text></xsl:text></xsl:when>
						<xsl:otherwise><xsl:text> off</xsl:text></xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Pages']/ui:value"/></caption>
					<thead>
						<tr>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'name']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Access level']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modules']/ui:value"/></th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/schema:WebPage">
							<xsl:sort select="schema:name"/>
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="$modpagealias"/>
										<xsl:with-param name="options">
											<xsl:text>page_nid=</xsl:text><xsl:value-of select="schema:identifier"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange3"/>

								</xsl:attribute>
								<td>
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="$modpagealias"/>
												<xsl:with-param name="options">
													<xsl:text>page_nid=</xsl:text><xsl:value-of select="schema:identifier"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:value-of select="schema:name"/>
									</a>
								</td>
								<td>
									<xsl:variable name="level-resource" select="luna:level/@rdf:resource"/>
									<xsl:value-of select="/rdf:RDF/luna:level[@rdf:about = $level-resource]/schema:name"/>
								</td>
								<td>
									<xsl:for-each select="luna:mod">
										<xsl:sort select="@rdf:resource"/>
										<xsl:variable name="resource" select="@rdf:resource"/>
										<xsl:value-of select="/rdf:RDF/luna:mod[@rdf:about = $resource]/schema:name"/>
										<xsl:if test="not(position() = last())">
											<xsl:text>, </xsl:text>
										</xsl:if>
									</xsl:for-each>
								</td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="groupslist">
		<xsl:param name="expand">1</xsl:param>
		<xsl:param name="modpagealias">
			<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_groups']/luna:alias"/>
		</xsl:param>
		<div id="GroupsList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Groups']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Groups']/ui:value"/></caption>
					<thead>
						<tr>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'name']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Accessible levels']/ui:value"/></th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/luna:group">
							<xsl:sort select="schema:name"/>
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="$modpagealias"/>
										<xsl:with-param name="options">
											<xsl:text>group_lid=</xsl:text><xsl:value-of select="luna:lid"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange3"/>

								</xsl:attribute>
								<td class="nowrap">
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="$modpagealias"/>
												<xsl:with-param name="options">
													<xsl:text>group_lid=</xsl:text><xsl:value-of select="luna:lid"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:value-of select="schema:name"/>
									</a>
								</td>
								<td>
									<xsl:for-each select="luna:level">
										<xsl:sort select="@rdf:resource"/>
										<xsl:variable name="res" select="@rdf:resource"/>
										<xsl:value-of select="/rdf:RDF/luna:level[@rdf:about = $res]/schema:name"/>
										<xsl:if test="not(position() = last())">
											<xsl:text>, </xsl:text>
										</xsl:if>
									</xsl:for-each>
								</td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="levelslist">
		<xsl:param name="expand">1</xsl:param>
		<xsl:param name="modpagealias">
			<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_levels']/luna:alias"/>
		</xsl:param>
		<div id="LevelsList" class="box">
			<h2>
				<xsl:attribute name="class">
					<xsl:text>box-handle</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text> expanded</xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> collapsed</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Levels']/ui:value"/>
			</h2>
			<div>
				<xsl:attribute name="class">
					<xsl:text>box-content boxtable</xsl:text>
					<xsl:choose>
						<xsl:when test="$expand = '1'">
							<xsl:text></xsl:text>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text> off</xsl:text>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
				<table class="zebra">
					<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Levels']/ui:value"/></caption>
					<thead>
						<tr>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'name']/ui:value"/></th>
							<th><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Groups admitted to this level']/ui:value"/></th>
						</tr>
					</thead>
					<tbody>
						<xsl:for-each select="/rdf:RDF/luna:level">
							<xsl:sort select="schema:name"/>
							<tr>
								<xsl:attribute name="class">
									<xsl:text>active</xsl:text>
									<xsl:if test="not(luna:isActive = '1')">
										<xsl:text> inactive</xsl:text>
									</xsl:if>
								</xsl:attribute>
								<xsl:attribute name="data-href">

									<xsl:call-template name="link">
										<xsl:with-param name="alias" select="$modpagealias"/>
										<xsl:with-param name="options">
											<xsl:text>level_lid=</xsl:text><xsl:value-of select="luna:lid"/>
										</xsl:with-param>
									</xsl:call-template>
									<xsl:call-template name="onchange3"/>

								</xsl:attribute>
								<td class="nowrap">
									<a>
										<xsl:attribute name="href">
											<xsl:call-template name="link">
												<xsl:with-param name="alias" select="$modpagealias"/>
												<xsl:with-param name="options">
													<xsl:text>level_lid=</xsl:text><xsl:value-of select="luna:lid"/>
												</xsl:with-param>
											</xsl:call-template>
										</xsl:attribute>
										<xsl:value-of select="schema:name"/>
									</a>
								</td>
								<td>
									<xsl:for-each select="luna:group">
										<xsl:sort select="/rdf:RDF/luna:group[@rdf:about = @rdf:resource]/luna:lid"/>
										<xsl:variable name="resource" select="@rdf:resource"/>
										<xsl:value-of select="/rdf:RDF/luna:group[@rdf:about = $resource]/schema:name"/>
										<xsl:if test="not(position() = last())">
											<xsl:text>, </xsl:text>
										</xsl:if>
									</xsl:for-each>
								</td>
							</tr>
						</xsl:for-each>
					</tbody>
				</table>
			</div>
		</div>
	</xsl:template>

</xsl:stylesheet>
