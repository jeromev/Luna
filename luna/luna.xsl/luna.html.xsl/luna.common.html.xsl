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

	<xsl:strip-space elements="*"/>

	<xsl:template name="cutstring">
		<xsl:param name="string"/>
		<xsl:param name="length">24</xsl:param>
		<xsl:param name="before">0</xsl:param>
		<xsl:choose>
			<xsl:when test="$before = 1">
				<xsl:choose>
					<xsl:when test="string-length($string) &gt; $length">
						<xsl:value-of select="substring($string, $length)"/>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="$string"/>
					</xsl:otherwise>
				</xsl:choose>
			</xsl:when>
			<xsl:otherwise>
				<xsl:choose>
					<xsl:when test="string-length($string) &gt; $length">
						<xsl:value-of select="substring($string, 0, $length)"/><xsl:text>…</xsl:text>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="$string"/>
					</xsl:otherwise>
				</xsl:choose>
			</xsl:otherwise>
		</xsl:choose>

	</xsl:template>

	<xsl:template match="/rdf:RDF/ui:message">
		<p>
			<xsl:attribute name="class">
				<xsl:text>message </xsl:text><xsl:value-of select="ui:code"/>
			</xsl:attribute>
			<xsl:apply-templates select="ui:value"/>
		</p>
	</xsl:template>

	<xsl:template match="keywords" mode="header" priority="2">
		<meta name="keywords">
			<xsl:attribute name="content"><xsl:apply-templates select="keyword"/></xsl:attribute>
		</meta>
	</xsl:template>
	<xsl:template match="keyword">
		<xsl:value-of select="."/>
		<xsl:if test="position() != last()">
			<xsl:text>, </xsl:text>
		</xsl:if>
	</xsl:template>

	<xsl:template name="buildSortURL">
		<xsl:param name="start">0</xsl:param>
		<xsl:value-of select="$pageurl"/>
		<xsl:choose>
			<xsl:when test="$cleanurls = 1">
				<xsl:text>?</xsl:text>
			</xsl:when>
			<xsl:otherwise>
				<xsl:text>&amp;</xsl:text>
			</xsl:otherwise>
		</xsl:choose>
		<xsl:text>start=</xsl:text><xsl:value-of select="$start"/>
		<xsl:text>&amp;order_by=</xsl:text><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value"/>
		<xsl:text>&amp;order_dir=</xsl:text><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'order_dir']/ui:value"/>
		<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
			<xsl:text>&amp;letter=</xsl:text><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
		</xsl:if>
		<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value">
			<xsl:text>&amp;limit=</xsl:text><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value"/>
		</xsl:if>
		<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'group_lid']/ui:value">
			<xsl:text>&amp;group_lid=</xsl:text><xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'group_lid']/ui:value"/>
		</xsl:if>
	</xsl:template>

	<xsl:template name="loop1">
		<xsl:param name="total">10</xsl:param>
		<xsl:param name="num">1</xsl:param>
		<xsl:if test="not($num &gt; $total)">
			<option>
				<xsl:attribute name="label"><xsl:value-of select="$num"/></xsl:attribute>
				<xsl:variable name="pagenumber"><xsl:value-of select="($num - 1) * /rdf:RDF/ui:pager/ui:perpage"/></xsl:variable>
				<xsl:attribute name="value">
					<xsl:call-template name="buildSortURL">
						<xsl:with-param name="start">
							<xsl:choose>
								<xsl:when test="$pagenumber &lt; 1">
									<xsl:text>0</xsl:text>
								</xsl:when>
								<xsl:otherwise>
									<xsl:value-of select="$pagenumber"/>
								</xsl:otherwise>
							</xsl:choose>
						</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="onchange4"/>
				</xsl:attribute>
				<xsl:if test="/rdf:RDF/ui:pager/ui:value = $num">
					<xsl:attribute name="selected">selected</xsl:attribute>
				</xsl:if>
				<xsl:value-of select="$num"/>
			</option>
			<xsl:call-template name="loop1">
				<xsl:with-param name="num" select="$num + 1"/>
				<xsl:with-param name="total" select="$total"/>
			</xsl:call-template>
		</xsl:if>
	</xsl:template>

	<xsl:template name="pager">
		<xsl:param name="mod">
			<xsl:value-of select="$mod_lid"/>
		</xsl:param>
		<xsl:if test="/rdf:RDF/ui:pager">
			<div class="pagination">
				<a class="first">
					<xsl:attribute name="href"><xsl:call-template name="buildSortURL"/></xsl:attribute>
					<xsl:attribute name="title">
						<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'First page']/ui:value"/>
					</xsl:attribute>
					<xsl:text>⇐</xsl:text>
				</a>
				<xsl:text> </xsl:text>
				<a class="prev">
					<xsl:attribute name="href">
						<xsl:call-template name="buildSortURL">
							<xsl:with-param name="start">
								<xsl:variable name="prevpagenumber"><xsl:value-of select="/rdf:RDF/ui:pager/ui:value - 2"/></xsl:variable>
								<xsl:choose>
									<xsl:when test="$prevpagenumber &lt; 1">
										<xsl:text>0</xsl:text>
									</xsl:when>
									<xsl:otherwise>
										<xsl:value-of select="$prevpagenumber * /rdf:RDF/ui:pager/ui:perpage"/>
									</xsl:otherwise>
								</xsl:choose>
							</xsl:with-param>
						</xsl:call-template>
					</xsl:attribute>
					<xsl:attribute name="title">
						<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Previous page']/ui:value"/>
					</xsl:attribute>
					<xsl:text>←</xsl:text>
				</a>
				<xsl:text> </xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Page: ']/ui:value"/>
				<select name="pagination" size="1" class="autowidth">
					<xsl:attribute name="aria-label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Page: ']/ui:value"/></xsl:attribute>
					<xsl:attribute name="data-navigate">1</xsl:attribute>
					<xsl:call-template name="loop1">
						<xsl:with-param name="total"><xsl:value-of select="/rdf:RDF/ui:pager/ui:total"/></xsl:with-param>
					</xsl:call-template>
				</select>
				<xsl:text>/</xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:pager/ui:total"/>
				<xsl:text> </xsl:text>
				<a class="next">
					<xsl:attribute name="href">
						<xsl:call-template name="buildSortURL">
							<xsl:with-param name="start">
								<xsl:variable name="nextpagenumber"><xsl:value-of select="/rdf:RDF/ui:pager/ui:value"/></xsl:variable>
								<xsl:choose>
									<xsl:when test="$nextpagenumber &lt; 1">
										<xsl:text>0</xsl:text>
									</xsl:when>
									<xsl:when test="$nextpagenumber &gt; (/rdf:RDF/ui:pager/ui:total - 1)">
										<xsl:value-of select="(/rdf:RDF/ui:pager/ui:total - 1) * /rdf:RDF/ui:pager/ui:perpage"/>
									</xsl:when>
									<xsl:otherwise>
										<xsl:value-of select="$nextpagenumber * /rdf:RDF/ui:pager/ui:perpage"/>
									</xsl:otherwise>
								</xsl:choose>
							</xsl:with-param>
						</xsl:call-template>
					</xsl:attribute>
					<xsl:attribute name="title">
						<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Next page']/ui:value"/>
					</xsl:attribute>
					<xsl:text>→</xsl:text>
				</a>
				<xsl:text> </xsl:text>
				<a class="last">
					<xsl:attribute name="href">
						<xsl:call-template name="buildSortURL">
							<xsl:with-param name="start">
								<xsl:value-of select="(/rdf:RDF/ui:pager/ui:total - 1) * /rdf:RDF/ui:pager/ui:perpage"/>
							</xsl:with-param>
						</xsl:call-template>
					</xsl:attribute>
					<xsl:attribute name="title">
						<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Last page']/ui:value"/>
					</xsl:attribute>
					<xsl:text>⇒</xsl:text>
				</a>
				<xsl:text> </xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Elements per page: ']/ui:value"/>
				<select name="perpage" size="1" class="autowidth">
					<xsl:attribute name="aria-label"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Elements per page: ']/ui:value"/></xsl:attribute>
					<xsl:attribute name="data-navigate">1</xsl:attribute>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">10</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">20</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">30</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">40</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">50</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">60</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">70</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">80</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">90</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">100</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">150</xsl:with-param>
					</xsl:call-template>
					<xsl:call-template name="optionlimit">
						<xsl:with-param name="limit">200</xsl:with-param>
					</xsl:call-template>
				</select>
			</div>
		</xsl:if>
	</xsl:template>

	<xsl:template name="optionlimit">
		<xsl:param name="limit">50</xsl:param>
		<option>
			<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value = $limit">
				<xsl:attribute name="selected"><xsl:text>selected</xsl:text></xsl:attribute>
			</xsl:if>
			<xsl:attribute name="value">
				<xsl:value-of select="$masternodeurl"/>
				<xsl:choose>
					<xsl:when test="$cleanurls = 1">
						<xsl:text>?</xsl:text>
					</xsl:when>
					<xsl:otherwise>
						<xsl:text>&amp;</xsl:text>
					</xsl:otherwise>
				</xsl:choose>
				<xsl:text>start=0</xsl:text>
				<xsl:text>&amp;order_by=</xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'order_by']/ui:value"/>
				<xsl:text>&amp;order_dir=</xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'order_dir']/ui:value"/>
				<xsl:text>&amp;limit=</xsl:text>
				<xsl:value-of select="$limit"/>
				<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
					<xsl:text>&amp;letter=</xsl:text>
					<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value"/>
				</xsl:if>
				<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value">
					<xsl:text>&amp;group_nid=</xsl:text>
					<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value"/>
				</xsl:if>
				<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value">
					<xsl:text>&amp;filter=</xsl:text>
					<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value"/>
				</xsl:if>
				<xsl:call-template name="onchange4"/>
			</xsl:attribute>
			<xsl:value-of select="$limit"/>
		</option>
	</xsl:template>

	<xsl:template name="onchange">
		<xsl:text>document.location=this[this.selectedIndex].value</xsl:text>
		<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] and not(/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] = '')">
			<xsl:text> + '?PHPSESSID=</xsl:text>
			<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID']"/>
			<xsl:text>'</xsl:text>
		</xsl:if>
		<xsl:text>;</xsl:text>
	</xsl:template>

	<xsl:template name="onchange2">
		<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] and not(/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] = '')">
			<xsl:text> + '&amp;PHPSESSID=</xsl:text>
			<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID']"/>
			<xsl:text>'</xsl:text>
		</xsl:if>
	</xsl:template>

	<xsl:template name="onchange3">
		<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] and not(/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] = '')">
			<xsl:text>?PHPSESSID=</xsl:text>
			<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID']"/>
		</xsl:if>
	</xsl:template>

		<xsl:template name="onchange4">
			<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] and not(/rdf:RDF/ui:request[ui:lid = 'PHPSESSID'] = '')">
				<xsl:text>&amp;PHPSESSID=</xsl:text>
				<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'PHPSESSID']"/>
			</xsl:if>
		</xsl:template>

	<xsl:template name="alphabeticlist">
		<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'alphabeticlist']">
			<div class="pagination alphabetic">
				<xsl:for-each select="letter">
					<xsl:text> </xsl:text>
					<xsl:if test="@current = '1'">
						<strong class="on">
							<a>
								<xsl:attribute name="href">
									<xsl:value-of select="ui:data[ui:lid = 'site_relative_url']"/>
									<xsl:value-of select="$masternodeurl"/>
									<xsl:text>?start=</xsl:text>
									<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'start']/ui:value"/>
									<xsl:text>&amp;order_by=</xsl:text>
									<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'order_by']/ui:value"/>
									<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
										<xsl:text>&amp;letter=</xsl:text>
										<xsl:value-of select="@value"/>
									</xsl:if>
									<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value">
										<xsl:text>&amp;limit=</xsl:text>
										<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value"/>
									</xsl:if>
									<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value">
										<xsl:text>&amp;group_nid=</xsl:text>
										<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value"/>
									</xsl:if>
									<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value">
										<xsl:text>&amp;filter=</xsl:text>
										<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value"/>
									</xsl:if>
								</xsl:attribute>
								<xsl:value-of select="@value"/>
							</a>
						</strong>
					</xsl:if>
					<xsl:if test="not(@current = '1')">
						<a>
							<xsl:attribute name="href">
								<xsl:value-of select="ui:data[ui:lid = 'site_relative_url']"/>
								<xsl:value-of select="$masternodeurl"/>
								<xsl:text>?start=0</xsl:text>
								<xsl:text>&amp;order_by=</xsl:text>
								<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'order_by']/ui:value"/>
								<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'letter']/ui:value">
									<xsl:text>&amp;letter=</xsl:text>
									<xsl:value-of select="@value"/>
								</xsl:if>
								<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value">
									<xsl:text>&amp;limit=</xsl:text>
									<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'limit']/ui:value"/>
								</xsl:if>
								<xsl:if test="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value">
									<xsl:text>&amp;group_nid=</xsl:text>
									<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = 'group_nid']/ui:value"/>
								</xsl:if>
								<xsl:if test="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value">
									<xsl:text>&amp;filter=</xsl:text>
									<xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'filter']/ui:value"/>
								</xsl:if>
							</xsl:attribute>
							<xsl:value-of select="@value"/>
						</a>
					</xsl:if>
					<xsl:text> · </xsl:text>
				</xsl:for-each>
			</div>
		</xsl:if>
	</xsl:template>

	<!-- {{{ csrf-input: anti-CSRF token, first child of every state-changing form -->
	<xsl:template name="csrf-input">
		<input type="hidden" name="csrf_token">
			<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'csrf_token']/ui:value"/></xsl:attribute>
		</input>
	</xsl:template>
	<!-- }}} -->
	<xsl:template name="forminput">
		<xsl:param name="type">text</xsl:param>
		<xsl:param name="mode">node</xsl:param>
		<xsl:param name="size">1</xsl:param>
		<xsl:param name="markdown">0</xsl:param>
		<xsl:param name="multiple">no</xsl:param>
		<xsl:param name="foreach"/>
		<xsl:param name="label"/>
		<xsl:param name="required"/>
		<!-- Which key a node-mode <option> is addressed by: 'nid' (schema:identifier, the
		     database counter) or 'lid' (luna:lid, the slug). Screens are moving to 'lid' one
		     at a time, so this stays opt-in until the last one has; callers passing 'lid' must
		     also pass $required and $default-value as lids, since both are compared against
		     the same key. -->
		<xsl:param name="option-key">nid</xsl:param>
		<xsl:param name="name"/>
		<xsl:param name="onchange"/>
		<xsl:param name="default-value">0</xsl:param>
		<xsl:param name="class"/>
		<xsl:param name="value">
			<xsl:choose>
				<xsl:when test="/rdf:RDF/ui:request/ui:lid = $name and not(/rdf:RDF/ui:message[ui:code = 'okay'])">
					<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = $name]/ui:value"/>
				</xsl:when>
				<xsl:when test="/rdf:RDF/ui:request/ui:lid = $name and /rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify'">
					<xsl:value-of select="/rdf:RDF/ui:request[ui:lid = $name]/ui:value"/>
				</xsl:when>
				<xsl:when test="/rdf:RDF/ui:request[ui:lid = 'submit'] and $class = 'checkbox'">
					<xsl:text>0</xsl:text>
				</xsl:when>
				<xsl:otherwise>
					<xsl:if test="not($default-value = '0')">
						<xsl:value-of select="$default-value"/>
					</xsl:if>
				</xsl:otherwise>
			</xsl:choose>
		</xsl:param>
		<label>
			<xsl:attribute name="for"><xsl:value-of select="$name"/></xsl:attribute>
			<xsl:attribute name="class"><xsl:value-of select="$class"/></xsl:attribute>
			<xsl:choose>
				<xsl:when test="$type = 'textarea'">
					<span class="label"><xsl:value-of select="$label"/></span>
					<textarea>
						<xsl:attribute name="name"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="id"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="rows">80</xsl:attribute>
						<xsl:attribute name="cols">40</xsl:attribute>
						<xsl:if test="$markdown = '1'">
							<xsl:attribute name="class">markdown</xsl:attribute>
						</xsl:if>
						<xsl:value-of select="$value"/>
					</textarea>
				</xsl:when>
				<xsl:when test="$type = 'select'">
					<span class="label"><xsl:value-of select="$label"/></span>
					<select>
						<xsl:if test="not($onchange = '')">
							<xsl:attribute name="data-navigate">1</xsl:attribute>
						</xsl:if>
						<xsl:attribute name="size">
							<xsl:choose>
								<xsl:when test="$size > 24"><xsl:text>24</xsl:text></xsl:when>
								<xsl:when test="$size = 1"><xsl:text>1</xsl:text></xsl:when>
								<xsl:otherwise><xsl:value-of select="$size + 1"/></xsl:otherwise>
							</xsl:choose>
						</xsl:attribute>
						<xsl:attribute name="id"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="name">
							<xsl:value-of select="$name"/>
							<xsl:if test="$multiple = 'yes' or $multiple = '1'">
								<xsl:text>[]</xsl:text>
							</xsl:if>
						</xsl:attribute>
						<xsl:if test="$multiple = 'yes' or $multiple = '1'">
							<xsl:attribute name="multiple">multiple</xsl:attribute>
						</xsl:if>
						<xsl:for-each select="$foreach">
							<!--xsl:choose>
								<xsl:when test="$mode = 'data'"><xsl:sort select="ui:lid"/></xsl:when>
								<xsl:otherwise><xsl:sort select="schema:name"/></xsl:otherwise>
							</xsl:choose-->
							<xsl:sort select="schema:name"/>
							<option>
								<xsl:choose>
									<xsl:when test="$mode = 'data'">
										<xsl:variable name="dataname"><xsl:value-of select="ui:lid"/></xsl:variable>
										<xsl:variable name="datavalue"><xsl:value-of select="ui:value"/></xsl:variable>
										<xsl:attribute name="label"><xsl:value-of select="ui:lid"/></xsl:attribute>
										<xsl:attribute name="value"><xsl:value-of select="$dataname"/></xsl:attribute>
										<xsl:choose>
											<xsl:when test="$dataname = $required">
												<xsl:attribute name="selected">selected</xsl:attribute>
											</xsl:when>
											<xsl:when test="(/rdf:RDF/ui:request[starts-with(ui:lid, $dataname)]/ui:value = $dataname) and not(/rdf:RDF/ui:message[ui:code = 'okay'])">
												<xsl:attribute name="selected">selected</xsl:attribute>
											</xsl:when>
											<xsl:when test="(/rdf:RDF/ui:request[starts-with(ui:lid, $dataname)]/ui:value = $dataname) and /rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify'">
												<xsl:attribute name="selected">selected</xsl:attribute>
											</xsl:when>
											<xsl:otherwise>
												<xsl:if test="not(/rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify') or (/rdf:RDF/ui:request[ui:lid = 'batch_submit'] = '1')">
													<xsl:if test="$dataname = $default-value">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:if>
												</xsl:if>
											</xsl:otherwise>
										</xsl:choose>
										<xsl:value-of select="$datavalue"/>
									</xsl:when>
									<xsl:otherwise>
										<xsl:variable name="option_value">
											<xsl:choose>
												<xsl:when test="$option-key = 'lid'"><xsl:value-of select="luna:lid"/></xsl:when>
												<xsl:otherwise><xsl:value-of select="schema:identifier"/></xsl:otherwise>
											</xsl:choose>
										</xsl:variable>
										<xsl:attribute name="label"><xsl:value-of select="schema:name"/></xsl:attribute>
										<xsl:attribute name="value"><xsl:value-of select="$option_value"/></xsl:attribute>
										<xsl:choose>
											<xsl:when test="$multiple = 'yes' or $multiple = '1'">
												<xsl:choose>
													<xsl:when test="$option_value = $required">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
													<xsl:when test="(/rdf:RDF/ui:request[starts-with(ui:lid, $name)]/ui:value = $option_value) and not(/rdf:RDF/ui:message[ui:code = 'okay'])">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
													<xsl:when test="(/rdf:RDF/ui:request[starts-with(ui:lid, $name)]/ui:value = $option_value) and /rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify'">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
													<xsl:otherwise>
														<xsl:if test="not(/rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify') or (/rdf:RDF/ui:request[ui:lid = 'batch_submit'] = '1')">
															<xsl:variable name="node_uri"><xsl:value-of select="@rdf:about"/></xsl:variable>
															<xsl:if test="not($default-value = '0')">
																<xsl:for-each select="$default-value">
																	<xsl:if test="@rdf:resource = $node_uri">
																		<xsl:attribute name="selected">selected</xsl:attribute>
																	</xsl:if>
																</xsl:for-each>
															</xsl:if>
														</xsl:if>
													</xsl:otherwise>
												</xsl:choose>
											</xsl:when>
											<xsl:otherwise>
												<xsl:choose>
													<xsl:when test="/rdf:RDF/ui:request[ui:lid = $name]/ui:value = $option_value and not(/rdf:RDF/ui:message[ui:code = 'okay'])">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
													<xsl:when test="/rdf:RDF/ui:request[ui:lid = $name]/ui:value = $option_value and /rdf:RDF/ui:request[ui:lid = 'mode']/ui:value = 'modify'">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
													<xsl:when test="$default-value = $option_value and not(/rdf:RDF/ui:message)">
														<xsl:attribute name="selected">selected</xsl:attribute>
													</xsl:when>
												</xsl:choose>
											</xsl:otherwise>
										</xsl:choose>
										<xsl:value-of select="schema:name"/>
									</xsl:otherwise>
								</xsl:choose>
							</option>
						</xsl:for-each>
					</select>
				</xsl:when>
				<xsl:when test="$type = 'checkbox'">
					<input>
						<xsl:attribute name="name"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="id"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="type"><xsl:value-of select="$type"/></xsl:attribute>
						<xsl:attribute name="class">autowidth</xsl:attribute>
						<xsl:attribute name="value">1</xsl:attribute>
						<xsl:if test="$value = '1'">
							<xsl:attribute name="checked">checked</xsl:attribute>
						</xsl:if>
					</input>
					<xsl:text> </xsl:text>
					<xsl:value-of select="$label"/>
				</xsl:when>
				<xsl:otherwise>
					<span class="label"><xsl:value-of select="$label"/></span>
					<input>
						<xsl:attribute name="name"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="id"><xsl:value-of select="$name"/></xsl:attribute>
						<xsl:attribute name="type"><xsl:value-of select="$type"/></xsl:attribute>
						<xsl:attribute name="value"><xsl:value-of select="$value"/></xsl:attribute>
					</input>
				</xsl:otherwise>
			</xsl:choose>
		</label>
	</xsl:template>

	<xsl:template name="quickjump">
		<select>
			<xsl:attribute name="name">quickjump</xsl:attribute>
			<xsl:attribute name="size">1</xsl:attribute>
			<xsl:attribute name="id">quickjump</xsl:attribute>
			<xsl:attribute name="data-navigate">1</xsl:attribute>
			<option label="" value=""><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Jump to page']/ui:value"/></option>
			<xsl:for-each select="/rdf:RDF/schema:WebPage">
				<xsl:if test="/node/user/accessible-levels//level = @level_id">
					<option>
						<xsl:attribute name="label"><xsl:value-of select="@name"/></xsl:attribute>
						<xsl:attribute name="value">
							<xsl:value-of select="ui:data[ui:lid = 'site_relative_url']"/><xsl:value-of select="@luna:url"/>
						</xsl:attribute>
						<xsl:value-of select="@name"/>
					</option>
				</xsl:if>
			</xsl:for-each>
		</select>
	</xsl:template>

	<xsl:template name="link">
		<xsl:param name="alias">0</xsl:param>
		<xsl:param name="options">0</xsl:param>
		<xsl:param name="lid">0</xsl:param>
		<xsl:choose>
			<xsl:when test="$alias = 0 or $alias = ''">
				<xsl:value-of select="$site_relative_url"/>
			</xsl:when>
			<xsl:otherwise>
				<xsl:choose>
					<xsl:when test="$cleanurls = 0">
						<xsl:value-of select="$site_relative_url"/><xsl:text>?path=</xsl:text><xsl:value-of select="$alias"/>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="$site_relative_url"/><xsl:value-of select="$alias"/>
					</xsl:otherwise>
				</xsl:choose>
			</xsl:otherwise>
		</xsl:choose>
		<xsl:if test="not($lid = 0 or $lid = '')">
			<xsl:choose>
				<xsl:when test="$cleanurls = '1'">
					<xsl:text>/</xsl:text><xsl:value-of select="$lid"/>
				</xsl:when>
				<xsl:otherwise>
					<xsl:text>&amp;lid=</xsl:text><xsl:value-of select="$lid"/>
				</xsl:otherwise>
			</xsl:choose>
		</xsl:if>
		<xsl:if test="not($options = 0 or $options = '')">
			<xsl:choose>
				<xsl:when test="$cleanurls = '1'">
					<xsl:text>?</xsl:text><xsl:value-of select="$options"/>
				</xsl:when>
				<xsl:when test="$cleanurls = '0'">
					<xsl:choose>
						<xsl:when test="($lid = 0 or $lid = '') and ($alias = 0 or $alias = '')">
							<xsl:text>?</xsl:text><xsl:value-of select="$options"/>
						</xsl:when>
						<xsl:otherwise>
							<xsl:text>&amp;</xsl:text><xsl:value-of select="$options"/>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:when>
			</xsl:choose>
		</xsl:if>
	</xsl:template>

	<xsl:template name="sitemap">
		<div id="SiteMap">
			<h2 class="box-handle collapsed"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Sitemap']/ui:value"/></h2>
			<div class="box-content">
				<ul class="tv">
					<xsl:for-each select="/rdf:RDF/schema:WebPage[luna:lid = 'root']">
						<xsl:variable name="root"><xsl:value-of select="@rdf:about"/></xsl:variable>
						<li>
							<a>
								<xsl:attribute name="href">
									<xsl:call-template name="link"><xsl:with-param name="alias" select="luna:alias"/></xsl:call-template>
								</xsl:attribute>
								<xsl:value-of select="schema:name"/>
							</a>
							<xsl:if test="count(/rdf:RDF/schema:WebPage/schema:isPartOf[@rdf:resource = $root]) &gt; 0">
								<ul class="tv">
									<xsl:for-each select="/rdf:RDF/schema:WebPage[schema:isPartOf/@rdf:resource = $root and @rdf:about != $root]">
										<xsl:sort select="schema:name"/>
										<xsl:variable name="child"><xsl:value-of select="@rdf:about"/></xsl:variable>
										<xsl:if test="luna:isActive = '1' and not($guest = '1' and luna:lid = 'logout') and not($guest = '0' and luna:lid = 'login')">
											<li>
												<a>
													<xsl:attribute name="href">
														<xsl:call-template name="link"><xsl:with-param name="alias" select="luna:alias"/></xsl:call-template>
													</xsl:attribute>
													<xsl:value-of select="schema:name"/>
												</a>
												<xsl:call-template name="recursesitemap"><xsl:with-param name="index" select="$child"/></xsl:call-template>
											</li>
										</xsl:if>
									</xsl:for-each>
								</ul>
							</xsl:if>
						</li>
					</xsl:for-each>
				</ul>
			</div>
		</div>
	</xsl:template>

	<xsl:template name="recursesitemap">
		<xsl:param name="index">0</xsl:param>
		<xsl:if test="not($index = 0) and not($index = '')">
			<xsl:if test="count(/rdf:RDF/schema:WebPage/schema:isPartOf[@rdf:resource = $index]) &gt; 0">
				<ul class="tv">
					<xsl:for-each select="/rdf:RDF/schema:WebPage[schema:isPartOf/@rdf:resource = $index]">
						<xsl:sort select="schema:name"/>
						<xsl:variable name="childindex"><xsl:value-of select="@rdf:about"/></xsl:variable>
						<xsl:if test="luna:isActive = '1' and not($guest = '1' and luna:lid = 'logout') and not($guest = '0' and luna:lid = 'login')">
							<li>
								<a>
									<xsl:attribute name="href">
										<xsl:call-template name="link"><xsl:with-param name="alias" select="luna:alias"/></xsl:call-template>
									</xsl:attribute>
									<xsl:value-of select="schema:name"/>
								</a>
								<xsl:call-template name="recursesitemap"><xsl:with-param name="index" select="$childindex"/></xsl:call-template>
							</li>
						</xsl:if>
					</xsl:for-each>
				</ul>
			</xsl:if>
		</xsl:if>
	</xsl:template>

	<xsl:template name="search">
		<div id="Search" class="box">
			<h2 class="box-handle collapsed"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Search']/ui:value"/></h2>
			<div class="box-content off">
				<form method="post" id="SearchForm">
					<xsl:attribute name="action">
						<xsl:value-of select="$site_relative_url"/><xsl:value-of select="node()[luna:lid = 'search']/luna:alias"/>
					</xsl:attribute>
					<fieldset>
						<p>
							<input type="text" name="lunasearchquery" id="lunasearchquery" value=""/>
							<input type="submit" name="lunasearchsubmit" id="lunasearchsubmit" class="submit off">
								<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Search']/ui:value"/></xsl:attribute>
							</input>
						</p>
						<div id="SearchTarget"></div>
					</fieldset>
					<xsl:call-template name="csrf-input"/>
				</form>
			</div>
		</div>
	</xsl:template>

</xsl:stylesheet>
