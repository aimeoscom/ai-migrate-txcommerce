<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates categories from TYPO3 commerce extension
 */
class TxCommerceMigrateCategories extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = '
		SELECT * FROM "tx_commerce_categories"
		WHERE "deleted" = 0 AND "uname" = \'\'
		ORDER BY "parent_category", "sorting", "uid"
		LIMIT ? OFFSET ?
	';


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceBase' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate categories', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_categories' ) === true )
		{
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
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'catalog.code', array_keys( $list ) ) );
		$search->setSlice( 0, count( $list ) );

		foreach( $manager->searchItems( $search, array( 'text' ) ) as $item ) {
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
			$item->setLabel( $entry['title'] );
			$item->setStatus( ! (bool) $entry['hidden'] );


			if( !isset( $map[$code] ) )
			{
				$parent = (int) $entry['parent_category'];
				$parentId = ( isset( $this->map[$parent] ) ? $this->map[$parent] : null );

				$manager->insertItem( $item, $parentId );
				$map[$code] = $item;
			}
			else
			{
				$manager->saveItem( $item );
			}

			$this->map[$code] = $item->getId();
		}

		$this->updateTexts( $list, $map );

		$manager->commit();
	}


	protected function updateTexts( array $entries, array $catItems )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'text' );
		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog/lists' );

		$listTypeId = $this->getTypeId( 'catalog/lists/type', 'text', 'default' );
		$langid = $this->additional->getLocale()->getLanguageId();

		$pos = 0;
		$mapping = array(
			'name' => 'title',
			'short' => 'subtitle',
			'long' => 'description',
			'metakeywords' => 'keywords',
			'url' => 'navtitle',
		);

		$manager->begin();

		foreach( $catItems as $code => $item )
		{
			$listItems = $item->getListItems( 'text', 'default' );

			foreach( $mapping as $type => $column )
			{
				if( $entries[$code][$column] == '' ) {
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

				$textItem->setTypeId( $this->getTypeId( 'text/type', 'catalog', $type ) );
				$textItem->setLabel( $type . ': ' . $item->getLabel() );
				$textItem->setContent( $entries[$code][$column] );
				$textItem->setLanguageId( $langid );
				$textItem->setDomain( 'catalog' );
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