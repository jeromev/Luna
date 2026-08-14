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

	<xsl:output
		encoding="utf-8"
		method="xml"
		indent="yes"
		omit-xml-declaration="no"
		doctype-public="-//W3C//DTD XHTML 1.0 Strict//EN"
		doctype-system="http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"/>

	<xsl:variable name="cleanurls"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'clean_urls']/ui:value"/></xsl:variable>
	<xsl:variable name="site_uri"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'site_uri']/ui:value/@rdf:resource"/></xsl:variable>
	<xsl:variable name="lang"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'lang']/ui:value"/></xsl:variable>
	<xsl:variable name="masternodelid"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'lid']/ui:value"/></xsl:variable>
	<xsl:variable name="masternodenid"><xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = $masternodelid]/schema:identifier"/></xsl:variable>
	<xsl:variable name="masternodeurl">
		<xsl:call-template name="link"><xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/></xsl:call-template>
	</xsl:variable>
	<xsl:variable name="site_relative_url"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'site_relative_url']/ui:value"/></xsl:variable>
	<xsl:variable name="pageurl"><xsl:value-of select="$masternodeurl"/></xsl:variable>
	<xsl:variable name="mod_nid"><xsl:value-of select="/rdf:RDF/luna:mod[luna:lid = $mod_lid]/schema:identifier"/></xsl:variable>
	<xsl:variable name="guest">
		<xsl:choose>
			<xsl:when test="/rdf:RDF/foaf:Person[luna:is_current = '1']/luna:is_guest = '1'">1</xsl:when>
			<xsl:otherwise>0</xsl:otherwise>
		</xsl:choose>
	</xsl:variable>

	<xsl:include href="./luna.common.html.xsl"/>

	<xsl:template match="/rdf:RDF">
		<html>
			<xsl:attribute name="lang"><xsl:value-of select="$lang"/></xsl:attribute>
			<xsl:attribute name="xml:lang"><xsl:value-of select="$lang"/></xsl:attribute>
			<head>
				<xsl:attribute name="profile"><xsl:text>http://ns.inria.fr/grddl/rdfa/</xsl:text></xsl:attribute>
				<meta name="viewport" content="width=device-width, initial-scale=1"/>
				<title>
					<xsl:value-of select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/schema:name"/>
					<xsl:text> · </xsl:text>
					<xsl:value-of select="ui:data[ui:lid = 'sitename']/ui:value"/>
				</title>
				<meta http-equiv="Content-Language">
					<xsl:attribute name="content"><xsl:value-of select="$lang"/></xsl:attribute>
				</meta>
				<link rel="schema.DC" href="http://purl.org/dc/elements/1.1/"/>
				<meta name="DC.Title">
					<xsl:attribute name="content">
						<xsl:value-of select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/schema:name"/>
						<xsl:text> · </xsl:text>
						<xsl:value-of select="ui:data[ui:lid = 'sitename']/ui:value"/>
					</xsl:attribute>
					<xsl:attribute name="lang"><xsl:value-of select="$lang"/></xsl:attribute>
					<xsl:attribute name="xml:lang"><xsl:value-of select="$lang"/></xsl:attribute>
				</meta>
				<meta name="DC.Type" content="text"/>
				<meta name="DC.Format" content="text/html"/>
				<meta name="DC.Identifier">
					<xsl:attribute name="content"><xsl:value-of select="$site_uri"/></xsl:attribute>
				</meta>
				<meta name="DC.Language" scheme="RFC3066">
					<xsl:attribute name="content"><xsl:value-of select="$lang"/></xsl:attribute>
				</meta>
				<meta name="DC.Date" scheme="W3CDTF">
					<xsl:attribute name="content"><xsl:value-of select="/rdf:RDF/ui:data[schema:identifier = $masternodenid]/dc:date"/></xsl:attribute>
				</meta>
				<link rel="stylesheet" type="text/css" media="all">
					<xsl:attribute name="href">
						<xsl:value-of select="$site_relative_url"/><xsl:text>css/styles.css</xsl:text>
					</xsl:attribute>
				</link>
				<link rel="alternate" type="application/rdf+xml">
					<xsl:attribute name="href">
						<xsl:call-template name="link">
							<xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/luna:alias"/>
							<xsl:with-param name="options">
								<xsl:text>output=xml</xsl:text>
							</xsl:with-param>
						</xsl:call-template>
					</xsl:attribute>
				</link>
				<script type="text/javascript">
					<xsl:attribute name="src">
						<xsl:value-of select="$site_relative_url"/><xsl:text>js/luna.js</xsl:text>
					</xsl:attribute>
				</script>
			</head>
			<body class="main">
				<div id="Page">
					<xsl:attribute name="class"><xsl:value-of select="$masternodelid"/></xsl:attribute>
					<header id="Top">
						<h1>
							<a>
								<xsl:attribute name="href">
									<xsl:call-template name="link"><xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[luna:lid = 'root']/luna:alias"/></xsl:call-template>
								</xsl:attribute>
								<span id="SiteTitle"><xsl:value-of select="ui:data[ui:lid = 'sitename']/ui:value"/></span>
							</a>
						</h1>
						<h1>
							<a>
								<xsl:attribute name="href">
									<xsl:value-of select="$pageurl"/>
								</xsl:attribute>
								<xsl:value-of select="/rdf:RDF/schema:WebPage[schema:identifier = $masternodenid]/schema:name"/>
							</a>
						</h1>
					</header>
					<hr/>
					<main id="Content">
						<xsl:if test="/rdf:RDF/ui:message">
							<div id="Messages" class="box"><xsl:apply-templates select="/rdf:RDF/ui:message"/></div>
						</xsl:if>
						<div id="Content-main"><xsl:call-template name="page"/></div>
						<div id="Bottom" class="box">
							<div class="box-content">
								<p>
									<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'You are here: ']/ui:value"/>
									<a>
										<xsl:attribute name="href"><xsl:value-of select="$pageurl"/></xsl:attribute>
										<xsl:value-of select="$pageurl"/>
									</a>
									<br/>
									<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'You are logged in as']/ui:value"/><xsl:text> </xsl:text>
									<strong><xsl:value-of select="/rdf:RDF/foaf:Person[luna:is_current = '1']/foaf:name"/></strong>
									<xsl:text> [</xsl:text>
									<xsl:choose>
										<xsl:when test="$guest = '1'">
											<a>
												<xsl:attribute name="href">
													<xsl:call-template name="link"><xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[luna:lid = 'login']/luna:alias"/></xsl:call-template>
												</xsl:attribute>
												<xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'login']/schema:name"/>
											</a>
										</xsl:when>
										<xsl:otherwise>
											<!-- Logout is a state change: POST form (not a GET link) so the CSRF token
											     never lands in a URL, browser history, or the server access log. -->
											<form method="post" class="logout-form">
												<xsl:attribute name="action">
													<xsl:call-template name="link"><xsl:with-param name="alias" select="/rdf:RDF/schema:WebPage[luna:lid = 'logout']/luna:alias"/></xsl:call-template>
												</xsl:attribute>
												<input type="hidden" name="csrf_token">
													<xsl:attribute name="value"><xsl:value-of select="/rdf:RDF/ui:data[ui:lid = 'csrf_token']/ui:value"/></xsl:attribute>
												</input>
												<button type="submit" class="linkbutton"><xsl:value-of select="/rdf:RDF/schema:WebPage[luna:lid = 'logout']/schema:name"/></button>
											</form>
										</xsl:otherwise>
									</xsl:choose>
									<xsl:text>] </xsl:text>
									<br/>
									<xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Site powered by']/ui:value"/><xsl:text> </xsl:text><a href="https://github.com/jeromev/Luna">Luna</a><xsl:text> </xsl:text><xsl:value-of select="ui:data[ui:lid = 'lunaversion']/ui:value"/>
								</p>
							</div>
						</div>
					</main>
					<hr/>
					<nav id="Nav">
						<h1 class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Navigation']/ui:value"/></h1>
						<!-- <xsl:if test="not($guest = '1')">  -->
							<!-- <xsl:call-template name="search"/>  -->
							<xsl:call-template name="sitemap"/>
						<!-- </xsl:if>  -->
						<div id="Options">
							<xsl:if test="count(/rdf:RDF/ui:lang) &gt; 1">
								<h2 class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'langs']/ui:value"/></h2>
								<ul class="langs">
									<xsl:for-each select="/rdf:RDF/ui:lang">
										<li>
											<a>
												<xsl:if test="ui:selected = '1'">
													<xsl:attribute name="class"><xsl:text>on</xsl:text></xsl:attribute>
												</xsl:if>
												<xsl:attribute name="href"><xsl:value-of select="ui:link"/></xsl:attribute>
												<xsl:attribute name="title"><xsl:value-of select="ui:value"/></xsl:attribute>
												<xsl:value-of select="ui:value"/>
											</a>
										</li>
									</xsl:for-each>
								</ul>
							</xsl:if>
							<!-- <xsl:if test="not($guest = '1')">  -->
								<h2 class="off"><xsl:value-of select="/rdf:RDF/ui:vocabulary[ui:lid = 'Output formats']/ui:value"/></h2>
								<ul class="outputs">
									<xsl:for-each select="/rdf:RDF/ui:output-format">
										<li>
											<a>
												<xsl:if test="ui:selected = '1'">
													<xsl:attribute name="class"><xsl:text>on</xsl:text></xsl:attribute>
												</xsl:if>
												<xsl:attribute name="href"><xsl:value-of select="ui:link"/></xsl:attribute>
												<xsl:attribute name="title"><xsl:value-of select="ui:value"/></xsl:attribute>
												<xsl:value-of select="ui:lid"/>
											</a>
										</li>
									</xsl:for-each>
								</ul>
							<!-- </xsl:if>  -->
						</div>
					</nav>
				</div>
			</body>
		</html>
	</xsl:template>

</xsl:stylesheet>
