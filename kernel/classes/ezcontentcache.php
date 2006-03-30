<?php
//
// Definition of eZContentCache class
//
// Created on: <12-Dec-2002 16:53:41 amos>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.8.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file ezcontentcache.php
*/

/*!
  \class eZContentCache ezcontentcache.php
  \brief The class eZContentCache does

*/

include_once( 'lib/ezutils/classes/ezsys.php' );
include_once( "lib/ezfile/classes/ezdir.php" );

// The timestamp for the cache format, will expire
// cache which differs from this.
define( 'EZ_CONTENT_CACHE_CODE_DATE', 1064816011 );

class eZContentCache
{
    /*!
     Constructor
    */
    function eZContentCache()
    {
    }

    function cachePathInfo( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList, $layout, $cacheTTL = false,
                            $parameters = array() )
    {
        $md5Input = array( $nodeID, $viewMode, $language );
        $md5Input[] = $offset;
        $md5Input = array_merge( $md5Input, $layout );
        sort( $roleList );
        $md5Input = array_merge( $md5Input, $roleList );
        sort( $discountList );
        $md5Input = array_merge( $md5Input, $discountList );
        if ( $cacheTTL == true )
            $md5Input = array_merge( $md5Input, "cache_ttl" );
        if ( isset( $parameters['view_parameters'] ) )
        {
            $viewParameters = $parameters['view_parameters'];
            ksort( $viewParameters );
            foreach ( $viewParameters as $viewParameterName => $viewParameter )
            {
                if ( !$viewParameter )
                    continue;
                $md5Input = array_merge( $md5Input, 'vp:' . $viewParameterName . '=' . $viewParameter );
            }
        }
        $md5Text = md5( implode( '-', $md5Input ) );
        $cacheFile = $nodeID . '-' . $md5Text . '.cache';
        $extraPath = eZDir::filenamePath( "$nodeID" );
        $ini =& eZINI::instance();
        $currentSiteAccess = $GLOBALS['eZCurrentAccess']['name'];
        $cacheDir = eZDir::path( array( eZSys::cacheDirectory(), $ini->variable( 'ContentSettings', 'CacheDir' ), $currentSiteAccess, $extraPath ) );
        $cachePath = eZDir::path( array( $cacheDir, $cacheFile ) );
        return array( 'dir' => $cacheDir,
                      'file' => $cacheFile,
                      'path' => $cachePath );
    }

    function exists( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList, $layout,
                     $parameters = array() )
    {
        $cachePathInfo = eZContentCache::cachePathInfo( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList,
                                                        $layout, false, $parameters );
        // VS-DBFILE

        require_once( 'kernel/classes/ezclusterfilehandler.php' );
        $cacheFile = eZClusterFileHandler::instance( $cachePathInfo['path'] );

        if ( $cacheFile->exists() )
        {
            $timestamp = $cacheFile->mtime();
            include_once( 'kernel/classes/ezcontentobject.php' );
            if ( eZContentObject::isCacheExpired( $timestamp ) )
            {
                eZDebugSetting::writeDebug( 'kernel-content-view-cache', 'cache expired #1' );
                return false;
            }
            eZDebugSetting::writeDebug( 'kernel-content-view-cache', "checking viewmode '$viewMode' #1" );
            if ( eZContentObject::isComplexViewModeCacheExpired( $viewMode, $timestamp ) )
            {
                eZDebugSetting::writeDebug( 'kernel-content-view-cache', "viewmode '$viewMode' cache expired #1" );
                return false;
            }

            return true;
        }

        eZDebugSetting::writeDebug( 'kernel-content-view-cache', 'cache used #1' );
        return false;
    }

    function restore( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList, $layout,
                      $parameters = array() )
    {
        $result = array();
        $cachePathInfo = eZContentCache::cachePathInfo( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList,
                                                        $layout, false, $parameters );
        $cacheDir = $cachePathInfo['dir'];
        $cacheFile = $cachePathInfo['file'];
        $cachePath = $cachePathInfo['path'];
        $timestamp = false;

        // VS-DBFILE

        require_once( 'kernel/classes/ezclusterfilehandler.php' );
        $cacheFile = eZClusterFileHandler::instance( $cachePath );

        if ( $cacheFile->exists() )
        {
            $timestamp = $cacheFile->mtime();
            include_once( 'kernel/classes/ezcontentobject.php' );
            if ( eZContentObject::isCacheExpired( $timestamp ) )
            {
                eZDebugSetting::writeDebug( 'kernel-content-view-cache', 'cache expired #2' );
                return false;
            }
            eZDebugSetting::writeDebug( 'kernel-content-view-cache', "checking viewmode '$viewMode' #1" );
            if ( eZContentObject::isComplexViewModeCacheExpired( $viewMode, $timestamp ) )
            {
                eZDebugSetting::writeDebug( 'kernel-content-view-cache', "viewmode '$viewMode' cache expired #2" );
                return false;
            }

        }

        if ( $viewMode == 'pdf' )
        {
            return $cachePath;
        }

        eZDebugSetting::writeDebug( 'kernel-content-view-cache', 'cache used #2' );

        $fileName = $cacheDir . "/" . $cacheFile;

        // VS-DBFILE : FIXME: We may need to cache PDF files locally.

        $cacheFile = eZClusterFileHandler::instance( $fileName );
        $contents = $cacheFile->fetchContents();

        $cachedArray = unserialize( $contents );

        $cacheTTL = $cachedArray['cache_ttl'];

        // Check if cache has expired
        if ( $cacheTTL > 0 )
        {
            $expiryTime = $timestamp + $cacheTTL;
            if ( time() > $expiryTime )
            {
                return false;
            }
        }

        // Check for template language timestamp
        $cacheCodeDate = $cachedArray['cache_code_date'];
        if ( $cacheCodeDate != EZ_CONTENT_CACHE_CODE_DATE )
            return false;

        $viewMode = $cachedArray['content_info']['viewmode'];

        $res =& eZTemplateDesignResource::instance();
        $res->setKeys( array( array( 'node', $nodeID ),
                              array( 'view_offset', $offset ),
                              array( 'viewmode', $viewMode )
                              ) );
        $result['content_info'] = $cachedArray['content_info'];
        $result['content'] = $cachedArray['content'];

        $result['view_parameters'] = $cachedArray['content_info']['view_parameters'];

        foreach ( array( 'path', 'node_id', 'section_id', 'navigation_part' ) as $item )
        {
            if ( isset( $cachedArray[$item] ) )
            {
                $result[$item] = $cachedArray[$item];
            }
        }

        // set section id
        include_once( 'kernel/classes/ezsection.php' );
        eZSection::setGlobalID( $cachedArray['section_id'] );
        return $result;
    }

    function store( $siteDesign, $objectID, $classID, $classIdentifier,
                    $nodeID, $parentNodeID, $nodeDepth, $urlAlias, $viewMode, $sectionID,
                    $language, $offset, $roleList, $discountList, $layout, $navigationPartIdentifier,
                    $result, $cacheTTL = -1,
                    $parameters = array() )
    {
        $cachePathInfo = eZContentCache::cachePathInfo( $siteDesign, $nodeID, $viewMode, $language, $offset, $roleList, $discountList,
                                                        $layout, false, $parameters );
        $cacheDir = $cachePathInfo['dir'];
        $cacheFile = $cachePathInfo['file'];

        $serializeArray = array();

        if ( isset( $parameters['view_parameters']['offset'] ) )
        {
            $offset = $parameters['view_parameters']['offset'];
        }
        $viewParameters = false;
        if ( isset( $parameters['view_parameters'] ) )
        {
            $viewParameters = $parameters['view_parameters'];
        }
        $contentInfo = array( 'site_design' => $siteDesign,
                              'node_id' => $nodeID,
                              'parent_node_id' => $parentNodeID,
                              'node_depth' => $nodeDepth,
                              'url_alias' => $urlAlias,
                              'object_id' => $objectID,
                              'class_id' => $classID,
                              'class_identifier' => $classIdentifier,
                              'navigation_part' => $navigationPartIdentifier,
                              'viewmode' => $viewMode,
                              'language' => $language,
                              'offset' => $offset,
                              'view_parameters' => $viewParameters,
                              'role_list' => $roleList,
                              'discount_list' => $discountList,
                              'section_id' => $result['section_id'] );

        $serializeArray['content_info'] = $contentInfo;

        foreach ( array( 'path', 'node_id', 'section_id', 'navigation_part' ) as $item )
        {
            if ( isset( $result[$item] ) )
            {
                $serializeArray[$item] = $result[$item];
            }
        }

        $serializeArray['cache_ttl'] = $cacheTTL;

        $serializeArray['cache_code_date'] = EZ_CONTENT_CACHE_CODE_DATE;
        $serializeArray['content'] = $result['content'];

        $serializeString = serialize( $serializeArray );

        if ( !file_exists( $cacheDir ) )
        {
            include_once( 'lib/ezfile/classes/ezdir.php' );
            $ini =& eZINI::instance();
            $perm = octdec( $ini->variable( 'FileSettings', 'StorageDirPermissions' ) );
            eZDir::mkdir( $cacheDir, $perm, true );
        }

        $path = $cacheDir . '/' . $cacheFile;
        $uniqid = md5( uniqid( 'ezpcache'. getmypid(), true ) );

        // VS-DBFILE : FIXME: Use some kind of one-shot atomic storing here.
        //             FIXME: use permissions provided in FileSettings:StorageFilePermissions.


        require_once( 'kernel/classes/ezclusterfilehandler.php' );
        $file = eZClusterFileHandler::instance( "$cacheDir/$uniqid" );
        $file->storeContents( $serializeString, 'viewcache', 'pdf' );
        $file->move( $path );

        return true;
    }

    function calculateCleanupValue( $nodeCount )
    {
        return $nodeCount;
    }

    function inCleanupThresholdRange( $value )
    {
        $ini =& eZINI::instance();
        $threshold = $ini->variable( 'ContentSettings', 'CacheThreshold' );
        return ( $value < $threshold );
    }

    function cleanup( $nodeList )
    {
        // JB start
        // The view-cache has a different storage structure than before:
        // var/cache/content/<siteaccess>/<extra-path>/<nodeID>-<hash>.cache
        // Also it uses the cluster file handler to delete files using a wildcard (glob style).
        $ini =& eZINI::instance();
        $cacheBaseDir = eZDir::path( array( eZSys::cacheDirectory(), $ini->variable( 'ContentSettings', 'CacheDir' ) ) );

        require_once( 'kernel/classes/ezclusterfilehandler.php' );
        $fileHandler = eZClusterFileHandler::instance();

        // Figure out the siteaccess which are related, first using the new
        // INI setting RelatedSiteAccessList then the old existing one
        // AvailableSiteAccessList
        if ( $ini->hasVariable( 'SiteAccessSettings', 'RelatedSiteAccessList' ) )
        {
            $relatedSiteAccessList = $ini->variable( 'SiteAccessSettings', 'RelatedSiteAccessList' );
            if ( !is_array( $relatedSiteAccessList ) )
            {
                $relatedSiteAccessList = array( $relatedSiteAccessList );
            }
            $relatedSiteAccessList[] = $GLOBALS['eZCurrentAccess']['name'];
            $siteAccesses = array_unique( $relatedSiteAccessList );
        }
        else
        {
            $siteAccesses = $ini->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' );
        }
        if ( !$siteAccesses )
        {
            return;
        }

        $siteAccessesDirs = implode( ',', $siteAccesses );
        $commonPath = "$cacheBaseDir/\{$siteAccessesDirs}";
        foreach ( $nodeList as $nodeID )
        {
            $extraPath = eZDir::filenamePath( $nodeID );
            $fileHandler->fileDeleteByWildcard( "$commonPath/$extraPath/$nodeID-*.cache" );
        }
    }
}

?>
