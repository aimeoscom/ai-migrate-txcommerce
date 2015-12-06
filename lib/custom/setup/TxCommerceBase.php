<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates from TYPO3 commerce extension
 */
class TxCommerceBase extends \Aimeos\MW\Setup\Task\Base
{
	private $typeIds = array();


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'MShopAddTypeDataDefault' );
	}


	/**
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies()
	{
		return array( 'DemoRebuildIndex' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
	}


	protected function getMimeType( $path )
	{
		switch( pathinfo( $path, PATHINFO_EXTENSION ) )
		{
			case 'gif':
				return 'image/gif';
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			case 'png':
				return 'image/png';
			case 'svg':
			case 'svgz':
				return 'image/svg+xml';
			case 'tiff':
				return 'image/tiff';
		}

		return 'application/octet-stream';
	}


	protected function getProductIds( array $prodCodes )
	{
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.code', $prodCodes ) );
		$search->setSlice( 0, count( $prodCodes ) );

		foreach( $manager->searchItems( $search ) as $id => $item )
		{
			$map[$item->getCode()] = $item->getId();
			unset( $item );
		}

		return $map;
	}


	/**
	 * Returns the attribute type item specified by the code.
	 *
	 * @param string $prefix Domain prefix for the manager, e.g. "media/type"
	 * @param string $domain Domain of the type item
	 * @param string $code Code of the type item
	 * @return \Aimeos\MShop\Common\Item\Type\Iface Type item
	 * @throws \Exception If no item is found
	 */
	protected function getTypeId( $prefix, $domain, $code )
	{
		if( !isset( $this->typeIds[$prefix][$domain][$code] ) )
		{
			$manager = \Aimeos\MShop\Factory::createManager( $this->additional, $prefix );
			$prefix = str_replace( '/', '.', $prefix );

			$search = $manager->createSearch();
			$expr = array(
				$search->compare( '==', $prefix . '.domain', $domain ),
				$search->compare( '==', $prefix . '.code', $code ),
			);
			$search->setConditions( $search->combine( '&&', $expr ) );
			$result = $manager->searchItems( $search );

			if( ( $item = reset( $result ) ) === false ) {
				throw new \Exception( sprintf( 'No type item for "%1$s/%2$s" in "%3$s" found', $domain, $code, $prefix ) );
			}

			$this->typeIds[$prefix][$domain][$code] = $item->getId();
		}

		return $this->typeIds[$prefix][$domain][$code];
	}


	protected function getText( $row, array $names )
	{
		$value = '';

		foreach( $names as $name )
		{
			if( isset( $row[$name] ) && $row[$name] != '' ) {
				$value = $row[$name];
			}
		}

		return $value;
	}
}