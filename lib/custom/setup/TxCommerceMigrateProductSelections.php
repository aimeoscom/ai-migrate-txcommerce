<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates product selections from TYPO3 commerce extension
 */
class TxCommerceMigrateProductSelections extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = array(
		'product' => '
			SELECT p.* FROM "tx_commerce_products" p
			JOIN "tx_commerce_articles" a ON a."uid_product" = p."uid"
			WHERE p."uname" = \'\' AND p."deleted" = 0 AND a."deleted" = 0
			GROUP BY p."uid"
			HAVING COUNT(*) > 1
			ORDER BY p."uid"
			LIMIT ? OFFSET ?
		',
		'article' => '
			SELECT a."uid", a."uid_product"
			FROM "tx_commerce_articles" a
			WHERE a."deleted" = 0 AND a."uid_product" IN (:list)
			ORDER BY a."uid_product", a."sorting", a."uid"
		'
	);


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceMigrateArticles', 'TxCommerceMigratePrices' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate product selections', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_products' ) === true
			&& $this->schema->tableExists( 'tx_commerce_articles' ) === true
		) {
			$offset = 0;
			$conn = $this->getConnection( 'db' );

			$stmt = $conn->create( $this->sql['product'] );

			do
			{
				$this->msg( 'From ' . ($offset + 1) . ' to ' . ($offset + $this->size), 1 );

				$stmt->bind( 1, $this->size, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $offset, \Aimeos\MW\DB\Statement\Base::PARAM_INT );

				$result = $stmt->execute();
				$list = array();

				while( ( $row = $result->fetch() ) !== false )
				{
					$list['select-' . $row['uid']] = $row;
					$ids[$row['uid']] = null;
				}

				$result->finish();

				$this->update( $list, array_keys( $ids ) );
				$offset += count( $list );

				$this->status( 'done' );
			}
			while( count( $list ) === $this->size );
		}
	}


	protected function update( array $list, array $ids )
	{
		$map = $prodIds = array();
		$typeId = $this->getTypeId( 'product/type', 'product', 'select' );
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.code', array_keys( $list ) ) );
		$search->setSlice( 0, count( $list ) );

		foreach( $manager->searchItems( $search, array( 'text', 'media', 'price' ) ) as $id => $item ) {
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
			$item->setLabel( $entry['title'] );
			$item->setStatus( ! (bool) $entry['hidden'] );

			if( $entry['starttime'] > 0 ) {
				$item->setDateStart( date( 'Y-m-d H:i:s', $entry['starttime'] ) );
			}

			if( $entry['endtime'] > 0 ) {
				$item->setDateEnd( date( 'Y-m-d H:i:s', $entry['endtime'] ) );
			}

			$manager->saveItem( $item );
			$map[$code] = $item;
		}

		$this->updateRef( $ids, $map );
		$this->updateMedia( $list, $map );

		$manager->commit();
	}


	protected function updateRef( array $ids, array $map )
	{
		$articles = array();
		$conn = $this->getConnection( 'db' );

		$sql = str_replace( ':list', '\'' . implode( '\',\'', $ids ) . '\'', $this->sql['article'] );
		$result = $conn->create( $sql )->execute();

		while( ( $row = $result->fetch() ) !== false ) {
			$articles[$row['uid']] = $row;
		}

		$result->finish();


		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$prodListItem = $listManager->createItem();
		$prodListItem->setTypeId( $this->getTypeId( 'product/lists/type', 'product', 'default' ) );
		$prodListItem->setDomain( 'product' );
		$prodListItem->setStatus( 1 );


		$pos = 0;
		$priceMap = array();
		$variants = $this->getProductItems( array_keys( $articles ), array( 'price ' ) );

		foreach( $articles as $code => $entry )
		{
			$selectCode = 'select-' . $entry['uid_product'];
			$priceMap[$selectCode] = array();

			if( isset( $map[$selectCode] ) && isset( $variants[$entry['uid']] ) )
			{
				if( $prodListItem->getParentId() == $map[$selectCode]->getId() ) {
					$pos = 0;
				}

				$prodListItem->setId( null );
				$prodListItem->setParentId( $map[$selectCode]->getId() );
				$prodListItem->setRefId( $variants[$entry['uid']]->getId() );
				$prodListItem->setPosition( $pos++ );

				try {
					$listManager->saveItem( $prodListItem, false );
				} catch( \Aimeos\MW\DB\Exception $e ) {} // duplicate entry


				$prices = $variants[$entry['uid']]->getRefItems( 'price', 'default', 'default' );
				$priceMap[$selectCode] = array_merge( $priceMap[$selectCode], $prices );
			}
		}


		$priceManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'price' );
		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$priceListItem = $listManager->createItem();
		$priceListItem->setTypeId( $this->getTypeId( 'product/lists/type', 'price', 'default' ) );
		$priceListItem->setDomain( 'price' );
		$priceListItem->setPosition( 0 );
		$priceListItem->setStatus( 1 );

		foreach( $priceMap as $code => $priceItems )
		{
			$plist = array();

			foreach( $priceItems as $priceItem ) {
				$plist[$priceItem->getValue()] = $priceItem;
			}

			$key = min( array_keys( $plist ) );

			$priceListItem->setId( null );
			$priceListItem->setParentId( $map[$code]->getId() );
			$priceListItem->setRefId( $plist[$key]->getId() );

			try {
				$listManager->saveItem( $priceListItem, false );
			} catch( \Aimeos\MW\DB\Exception $e ) {} // duplicate entry
		}
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

			foreach( explode( ',', $list[$code]['images'] ) as $path )
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

		$mapping = array(
			'name' => 'title',
			'short' => 'subtitle',
			'long' => 'description',
			'metakeywords' => 'keywords',
			'url' => 'navtitle',
		);

		$manager->begin();

		foreach( $map as $code => $item )
		{
			$pos = 0;
			$listItems = $item->getListItems( 'text', 'default' );

			foreach( $mapping as $type => $column )
			{
				if( $list[$code][$column] == '' ) {
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
				$textItem->setContent( $list[$code][$column] );
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


	protected function getProductItems( array $prodCodes, $domains )
	{
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.code', $prodCodes ) );
		$search->setSlice( 0, count( $prodCodes ) );

		foreach( $manager->searchItems( $search, $domains ) as $id => $item ) {
			$map[$item->getCode()] = $item;
		}

		return $map;
	}
}