<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates product categories from TYPO3 commerce extension
 */
class TxCommerceMigrateProductCategories extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = '
		SELECT c."uid" AS "catcode", a."uid" AS "articlecode", p."uid" AS "selectcode"
		FROM "tx_commerce_categories" c
		JOIN "tx_commerce_products_categories_mm" pc ON pc."uid_foreign" = c."uid"
		JOIN "tx_commerce_products" p ON pc."uid_local" = p."uid"
		JOIN "tx_commerce_articles" a ON p."uid" = a."uid_product"
		GROUP BY p."uid"
		ORDER BY c."uid", p."sorting", a."sorting", a."uid"
		LIMIT ? OFFSET ?
	';


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceMigrateArticles', 'TxCommerceMigrateProductSelections', 'TxCommerceMigrateCategories' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate product categories', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_products_categories_mm' ) === true
			&& $this->schema->tableExists( 'tx_commerce_categories' ) === true
			&& $this->schema->tableExists( 'tx_commerce_products' ) === true
			&& $this->schema->tableExists( 'tx_commerce_articles' ) === true
		) {
			$offset = 0;
			$articles = $selects = array();

			$conn = $this->getConnection( 'db' );
			$stmt = $conn->create( $this->sql );

			do
			{
				$this->msg( 'From ' . ($offset + 1) . ' to ' . ($offset + $this->size), 1 );

				$stmt->bind( 1, $this->size, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $offset, \Aimeos\MW\DB\Statement\Base::PARAM_INT );

				$result = $stmt->execute();
				$list = array();
				$cnt = 0;

				while( ( $row = $result->fetch() ) !== false )
				{
					$selects['select-' . $row['selectcode']][] = $row;
					$articles[$row['articlecode']][] = $row;
					$list[$row['catcode']][] = $row;

					$cnt++;
				}

				$result->finish();

				$this->update( $list, $articles, $selects );
				$offset += $cnt;

				$this->status( 'done' );
			}
			while( $cnt === $this->size );
		}
	}


	protected function update( array $list, array $articles, array $selects )
	{
		$catIds = $this->getCategoryIds( array_keys( $list ) );
		$articleIds = $this->getProductIds( array_keys( $articles ) );
		$selectIds = $this->getProductIds( array_keys( $selects ) );


		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog/lists' );

		$listItem = $listManager->createItem();
		$listItem->setTypeId( $this->getTypeId( 'catalog/lists/type', 'product', 'default' ) );
		$listItem->setDomain( 'product' );
		$listItem->setStatus( 1 );

		$listManager->begin();

		foreach( $list as $code => $entries )
		{
			if( !isset( $catIds[$code] ) ) {
				continue;
			}

			$pos = 0;

			foreach( $entries as $entry )
			{
				if( isset( $selectIds['select-' . $entry['selectcode']] ) )
				{
					$listItem->setId( null );
					$listItem->setParentId( $catIds[$code] );
					$listItem->setRefId( $selectIds['select-' . $entry['selectcode']] );
					$listItem->setPosition( $pos++ );

					try {
						$listManager->saveItem( $listItem, false );
					} catch( \Aimeos\MW\DB\Exception $e ) {} // duplicate entry
				}
				else if( isset( $articleIds[$entry['articlecode']] ) )
				{
					$listItem->setId( null );
					$listItem->setParentId( $catIds[$code] );
					$listItem->setRefId( $articleIds[$entry['articlecode']] );
					$listItem->setPosition( $pos++ );

					try {
						$listManager->saveItem( $listItem, false );
					} catch( \Aimeos\MW\DB\Exception $e ) {} // duplicate entry
				}
			}
		}

		$listManager->commit();
	}


	protected function getCategoryIds( array $codes )
	{
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'catalog.code', $codes ) );
		$search->setSlice( 0, count( $codes ) );

		foreach( $manager->searchItems( $search ) as $id => $item )
		{
			$map[$item->getCode()] = $id;
			unset( $item );
		}

		return $map;
	}
}