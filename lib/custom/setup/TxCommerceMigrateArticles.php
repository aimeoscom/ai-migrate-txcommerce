<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates articles from TYPO3 commerce extension
 */
class TxCommerceMigrateArticles extends TxCommerceBase
{
	private $size = 100;

	private $sql = '
		SELECT a.*, p."hidden" AS "p_hidden", p."starttime" AS "p_starttime", p."endtime" AS "p_endtime",
			p."title" AS "p_title", p."subtitle" AS "p_subtitle", p."images" AS "p_images",
			p."description" AS "p_description", p."navtitle" AS "p_navtitle", p."keywords"
		FROM "tx_commerce_articles" a
		LEFT JOIN "tx_commerce_products" p ON p."uid" = a."uid_product" AND p."deleted" = 0 AND p."uname" = \'\'
		WHERE a."deleted" = 0 AND a."classname" = \'\'
		ORDER BY a."uid"
		LIMIT ? OFFSET ?
	';


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate articles', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_articles' ) === true
			&& $this->schema->tableExists( 'tx_commerce_products' ) === true
		) {
			$offset = 0;
			$conn = $this->getConnection( 'db' );

			$stmt = $conn->create( $this->sql );

			do
			{
				$this->msg( 'From ' . ($offset + 1) . ' to ' . ($offset + $this->size), 1 );

				$stmt->bind( 1, $this->size, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $offset, \Aimeos\MW\DB\Statement\Base::PARAM_INT );

				$result = $stmt->execute();
				$list = array();

				while( ( $row = $result->fetch() ) !== false ) {
					$list[$row['uid']] = $row;
				}

				$result->finish();

				$this->update( $list );
				$offset += count( $list );

				$this->status( 'done' );
			}
			while( count( $list ) === $this->size );
		}
	}


	protected function update( array $list )
	{
		$map = $prodIds = array();
		$typeId = $this->getTypeId( 'product/type', 'product', 'default' );
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.code', array_keys( $list ) ) );
		$search->setSlice( 0, count( $list ) );

		foreach( $manager->searchItems( $search, array( 'text', 'media' ) ) as $id => $item ) {
			$map[$item->getCode()] = $item;
		}

		$manager->begin();

		foreach( $list as $code => $entry )
		{
			if( !isset( $map[$code] ) ) {
				$item = $manager->createItem();
			} else {
				$item = $map[$code];
			}

			$item->setCode( $code );
			$item->setTypeId( $typeId );
			$item->setLabel( $this->getText( $entry, array( 'p_title', 'title' ) ) );
			$item->setStatus( !($entry['p_hidden'] || $entry['hidden']) );

			if( $entry['p_starttime'] > 0 ) {
				$item->setDateStart( date( 'Y-m-d H:i:s', $entry['p_starttime'] ) );
			}

			if( $entry['p_endtime'] > 0 ) {
				$item->setDateEnd( date( 'Y-m-d H:i:s', $entry['p_endtime'] ) );
			}

			if( $entry['starttime'] > 0 ) {
				$item->setDateStart( date( 'Y-m-d H:i:s', $entry['starttime'] ) );
			}

			if( $entry['endtime'] > 0 ) {
				$item->setDateEnd( date( 'Y-m-d H:i:s', $entry['endtime'] ) );
			}

			$manager->saveItem( $item );
			$map[$code] = $item;
		}

		$this->updateMedia( $list, $map );

		$manager->commit();
	}


	protected function updateMedia( array $list, array $map )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'media' );
		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$typeId = $this->getTypeId( 'media/type', 'product', 'default' );
		$listTypeId = $this->getTypeId( 'product/lists/type', 'media', 'default' );

		$manager->begin();

		foreach( $map as $code => $item )
		{
			$pos = 0;
			$listItems = $item->getListItems( 'media', 'default' );

			$images = explode( ',', $list[$code]['p_images'] );
			if( $list[$code]['images'] != '' ) {
				$images = explode( ',', $list[$code]['images'] );
			}

			foreach( $images as $path )
			{
				if( $path == '' ) {
					continue;
				}

				if( ( $listItem = array_shift( $listItems ) ) === null )
				{
					$listItem = $listManager->createItem();
					$mediaItem = $manager->createItem();
				}
				else
				{
					$mediaItem = $listItem->getRefItem();
				}

				$mediaItem->setTypeId( $typeId );
				$mediaItem->setMimeType( $this->getMimeType( $path ) );
				$mediaItem->setLabel( $item->getLabel() );
				$mediaItem->setLanguageId( null );
				$mediaItem->setDomain( 'product' );
				$mediaItem->setPreview( $path );
				$mediaItem->setUrl( $path );
				$mediaItem->setStatus( 1 );

				$manager->saveItem( $mediaItem );

				$listItem->setTypeId( $listTypeId );
				$listItem->setRefId( $mediaItem->getId() );
				$listItem->setParentId( $item->getId() );
				$listItem->setPosition( $pos++ );
				$listItem->setDomain( 'media' );
				$listItem->setStatus( 1 );

				$listManager->saveItem( $listItem, false );
			}
		}

		$this->updateTexts( $list, $map );

		$manager->commit();
	}


	protected function updateTexts( array $list, array $map )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'text' );
		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listTypeId = $this->getTypeId( 'product/lists/type', 'text', 'default' );
		$langid = $this->additional->getLocale()->getLanguageId();

		$manager->begin();

		foreach( $map as $code => $item )
		{
			$pos = 0;
			$listItems = $item->getListItems( 'text', 'default' );

			$mapping = array(
				'name' => $this->getText( $list[$code], array( 'p_title', 'title' ) ),
				'short' => $this->getText( $list[$code], array( 'p_subtitle', 'subtitle' ) ),
				'long' => $this->getText( $list[$code], array( 'p_description', 'description_extra' ) ),
				'url' => $this->getText( $list[$code], array( 'p_navtitle', 'navtitle' ) ),
				'metakeywords' => $this->getText( $list[$code], array( 'keywords' ) ),
			);

			foreach( $mapping as $type => $value )
			{
				if( $value == '' ) {
					continue;
				}

				if( ( $listItem = array_shift( $listItems ) ) === null )
				{
					$listItem = $listManager->createItem();
					$textItem = $manager->createItem();
				}
				else
				{
					$textItem = $listItem->getRefItem();
				}

				$textItem->setTypeId( $this->getTypeId( 'text/type', 'product', $type ) );
				$textItem->setLabel( $type . ': ' . $item->getLabel() );
				$textItem->setContent( $value );
				$textItem->setLanguageId( $langid );
				$textItem->setDomain( 'product' );
				$textItem->setStatus( 1 );

				$manager->saveItem( $textItem );

				$listItem->setTypeId( $listTypeId );
				$listItem->setRefId( $textItem->getId() );
				$listItem->setParentId( $item->getId() );
				$listItem->setPosition( $pos++ );
				$listItem->setDomain( 'text' );
				$listItem->setStatus( 1 );

				$listManager->saveItem( $listItem, false );
			}
		}

		$manager->commit();
	}
}