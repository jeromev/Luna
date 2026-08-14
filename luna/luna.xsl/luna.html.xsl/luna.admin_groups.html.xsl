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

	<xsl:variable name="mod_lid">mod_admin_groups</xsl:variable>

	<xsl:include href="./luna.header.html.xsl"/>
	<xsl:include href="./luna.common_admin.html.xsl"/>

	<xsl:variable name="modify_item_lid"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'modify_item_lid']/ui:value"/></xsl:variable>

	<xsl:template name="page">
		<xsl:if test="/rdf:RDF/luna:mod[luna:lid = $mod_lid]/luna:is_loaded = '1'">
			<xsl:if test="$modify_item_lid = ''">
				<div class="box">
					<form method="post" id="Addgroup">
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
								<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Add a group']/ui:value"/>
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
											<xsl:with-param name="name">add_group_is_inactive</xsl:with-param>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Deactivate']/ui:value"/></xsl:with-param>
										</xsl:call-template>
										<br />
									</div>
									<div class="col">
										<xsl:call-template name="forminput">
											<xsl:with-param name="name">add_group_lid</xsl:with-param>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/></xsl:with-param>
										</xsl:call-template>
										<br />
										<br/>
									</div>
									<div class="col">
										<xsl:call-template name="forminput">
											<xsl:with-param name="name">add_group_levels</xsl:with-param>
											<xsl:with-param name="type">select</xsl:with-param>
											<xsl:with-param name="option-key">lid</xsl:with-param>
											<xsl:with-param name="foreach" select="/rdf:RDF/luna:level"/>
											<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Accessible levels']/ui:value"/></xsl:with-param>
											<xsl:with-param name="multiple">yes</xsl:with-param>
											<xsl:with-param name="required" select="/rdf:RDF/luna:level[luna:lid = 'level_public']/luna:lid"/>
											<xsl:with-param name="size">
												<xsl:value-of select="count(/rdf:RDF/luna:level)"/>
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
				<xsl:call-template name="groupslist"/>
			</xsl:if>
			<xsl:if test="not($modify_item_lid = '')">
			<div class="box">
				<form method="post" id="Modifygroup">
					<xsl:attribute name="action">
						<xsl:value-of select="$pageurl"/>
					</xsl:attribute>
					<fieldset>
						<h2 class="box-handle expanded">
							<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modify the group']/ui:value"/>
							<xsl:text> </xsl:text>
							<em><xsl:value-of select="/rdf:RDF/luna:group[luna:lid = $modify_item_lid]/schema:name"/></em>
						</h2>
						<div class="box-content">
							<div class="fields">
								<div class="onecol">
									<xsl:call-template name="forminput">
										<xsl:with-param name="type">checkbox</xsl:with-param>
										<xsl:with-param name="name">modify_group_is_inactive</xsl:with-param>
										<xsl:with-param name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Deactivate']/ui:value"/></xsl:with-param>
										<xsl:with-param name="default-value">
											<xsl:choose>
												<xsl:when test="/rdf:RDF/luna:group[luna:lid = $modify_item_lid]/luna:isActive = '1'">
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
										<xsl:with-param name="name">modify_group_lid</xsl:with-param>
										<xsl:with-param name="label" select="/rdf:RDF/ui:vocabulary[ui:lid = 'Literal identifier']/ui:value"/>
										<xsl:with-param name="default-value" select="/rdf:RDF/luna:group[luna:lid = $modify_item_lid]/luna:lid"/>
									</xsl:call-template>
									<br />
								</div>
								<div class="col">
									<xsl:call-template name="forminput">
										<xsl:with-param name="name">modify_group_levels</xsl:with-param>
										<xsl:with-param name="type">select</xsl:with-param>
										<xsl:with-param name="option-key">lid</xsl:with-param>
										<xsl:with-param name="foreach" select="/rdf:RDF/luna:level"/>
										<xsl:with-param name="label" select="/rdf:RDF/ui:vocabulary[ui:lid = 'Accessible levels']/ui:value"/>
										<xsl:with-param name="multiple">yes</xsl:with-param>
										<xsl:with-param name="size" select="count(/rdf:RDF/luna:level)"/>
										<xsl:with-param name="default-value" select="/rdf:RDF/luna:group[luna:lid = $modify_item_lid]/luna:level"/>
										<xsl:with-param name="required" select="/rdf:RDF/luna:level[luna:lid = 'level_public']/luna:lid"/>
									</xsl:call-template>
									<br />
								</div>
							</div>
							<div class="submit">
								<input type="hidden" name="mode" value="modify"/>
								<input type="hidden" name="group_lid">
									<xsl:attribute name="value"><xsl:value-of select="$modify_item_lid"/></xsl:attribute>
								</input>
								<input type="hidden" name="modify_item_lid">
									<xsl:attribute name="value">
										<xsl:value-of select="$modify_item_lid"/>
									</xsl:attribute>
								</input>
								<input type="submit" class="submit" name="submit">
									<xsl:attribute name="value"><xsl:value-of  select="/rdf:RDF/ui:vocabulary[ui:lid = 'Modify']/ui:value"/></xsl:attribute>
								</input>
								<input type="submit" class="submit warning" name="submit">
									<xsl:attribute name="value"><xsl:value-of  select="/rdf:RDF/ui:vocabulary[ui:lid = 'Delete']/ui:value"/></xsl:attribute>
									<xsl:attribute name="data-confirm">

										<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Are you sure you want to delete this group?']/ui:value"/>

									</xsl:attribute>
								</input>
							</div>
						</div>
					</fieldset>
					<xsl:call-template name="csrf-input"/>
				</form>
			</div>
			<div id="UsersList" class="box">
				<form method="post" id="Modifyusers">
					<fieldset>
						<xsl:attribute name="action"><xsl:value-of select="$pageurl"/></xsl:attribute>
						<h2 class="box-handle expanded">
							<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Users who belong to this group']/ui:value"/>
						</h2>
						<div class="box-content boxtable">
							<table class="zebra">
								<caption class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Users who belong to this group']/ui:value"/></caption>
								<thead>
									<tr>
										<td colspan="7">
											<xsl:call-template name="pager"/>
										</td>
									</tr>
									<tr>
										<th><span class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Select one']/ui:value"/></span></th>
										<th>
											<a>
												<xsl:attribute name="href">
													<xsl:call-template name="link">
														<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
														<xsl:with-param name="options">
															<xsl:text>start=</xsl:text>
															<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
															<xsl:text>&amp;order_by=</xsl:text>
															<xsl:text>firstname</xsl:text>
															<xsl:text>&amp;group_lid=</xsl:text>
															<xsl:value-of select="$modify_item_lid"/>
															<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
																<xsl:text>&amp;letter=</xsl:text>
																<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
															</xsl:if>
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
														<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
														<xsl:with-param name="options">
															<xsl:text>start=</xsl:text>
															<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
															<xsl:text>&amp;order_by=</xsl:text>
															<xsl:text>lastname</xsl:text>
															<xsl:text>&amp;group_lid=</xsl:text>
															<xsl:value-of select="$modify_item_lid"/>
															<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
																<xsl:text>&amp;letter=</xsl:text>
																<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
															</xsl:if>
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
														<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
														<xsl:with-param name="options">
															<xsl:text>start=</xsl:text>
															<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
															<xsl:text>&amp;order_by=</xsl:text>
															<xsl:text>email</xsl:text>
															<xsl:text>&amp;group_lid=</xsl:text>
															<xsl:value-of select="$modify_item_lid"/>
															<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
																<xsl:text>&amp;letter=</xsl:text>
																<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
															</xsl:if>
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
														<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
														<xsl:with-param name="options">
															<xsl:text>start=</xsl:text>
															<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
															<xsl:text>&amp;order_by=</xsl:text>
															<xsl:text>regis_time</xsl:text>
															<xsl:text>&amp;group_lid=</xsl:text>
															<xsl:value-of select="$modify_item_lid"/>
															<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
																<xsl:text>&amp;letter=</xsl:text>
																<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
															</xsl:if>
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
														<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
														<xsl:with-param name="options">
															<xsl:text>start=</xsl:text>
															<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
															<xsl:text>&amp;order_by=</xsl:text>
															<xsl:text>last_time</xsl:text>
															<xsl:text>&amp;group_lid=</xsl:text>
															<xsl:value-of select="$modify_item_lid"/>
															<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
																<xsl:text>&amp;letter=</xsl:text>
																<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
															</xsl:if>
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
									<xsl:for-each select="/rdf:RDF/foaf:Person[luna:group/@rdf:resource = /rdf:RDF/luna:group[luna:lid = $modify_item_lid]/@rdf:about]">
										<xsl:variable name="user_nid"><xsl:value-of select="@rdf:resource"/></xsl:variable>
										<tr>
											<xsl:attribute name="class">
												<xsl:if test="not(luna:isActive = '1')">
													<xsl:text>deleted</xsl:text>
												</xsl:if>
											</xsl:attribute>
											<td>
												<input type="checkbox" class="autowidth">
													<xsl:attribute name="name">
														<xsl:text>modify_users_list[</xsl:text>
														<xsl:value-of select="luna:lid"/>
														<xsl:text>]</xsl:text>
													</xsl:attribute>
													<xsl:attribute name="value"><xsl:value-of select="luna:lid"/></xsl:attribute>
												</input>
											</td>
											<td><xsl:value-of select="foaf:firstName"/></td>
											<td><xsl:value-of select="foaf:surName"/></td>
											<td>
												<a>
													<xsl:attribute name="href">
														<xsl:call-template name="link">
															<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[luna:lid = 'admin_users']/luna:alias"/>
															<xsl:with-param name="options">
																<xsl:text>user_nid=</xsl:text>
																<xsl:value-of select="schema:identifier"/>
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
											<td><xsl:value-of select="luna:registration-date"/></td>
											<td><xsl:value-of select="luna:last-visit"/></td>
										</tr>
									</xsl:for-each>
								</tbody>
								<tfoot>
									<tr>
										<td colspan="7">
											<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'For the selection']/ui:value"/><xsl:text>: </xsl:text>
											<select>
												<xsl:attribute name="name">modify_users_action</xsl:attribute>
												<xsl:attribute name="size">1</xsl:attribute>
												<xsl:attribute name="id">modify_users_action</xsl:attribute>
												<xsl:attribute name="data-submit-on-change">1</xsl:attribute>
												<option label="" value=""><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Choose an action']/ui:value"/></option>
												<option>
													<xsl:attribute name="label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Delete from the group']/ui:value"/></xsl:attribute>
													<xsl:attribute name="value"><xsl:text>delete_from_group</xsl:text></xsl:attribute>
													<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Delete from the group']/ui:value"/>
												</option>
											</select>
										</td>
									</tr>
								</tfoot>
							</table>
							<input type="hidden" name="mode" value="modify"/>
							<input type="hidden" name="modify_group_lid">
								<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/luna:group[luna:lid = $modify_item_lid]/luna:lid"/></xsl:attribute>
							</input>
							<input type="hidden" name="modify_item_lid">
								<xsl:attribute name="value"><xsl:value-of select="$modify_item_lid"/></xsl:attribute>
							</input>
							<input type="hidden" name="group_lid">
								<xsl:attribute name="value"><xsl:value-of select="$modify_item_lid"/></xsl:attribute>
							</input>
							<input type="hidden" name="batch_submit" value="1"/>
							<input type="hidden" name="group_lid">
								<xsl:attribute name="value"><xsl:value-of select="$modify_item_lid"/></xsl:attribute>
							</input>
						</div>
					</fieldset>
					<xsl:call-template name="csrf-input"/>
				</form>
			</div>
			<xsl:call-template name="groupslist"><xsl:with-param name="expand">0</xsl:with-param></xsl:call-template>
		</xsl:if>
	</xsl:if>
	</xsl:template>

</xsl:stylesheet>
