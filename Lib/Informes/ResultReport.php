<?php
/**
 * This file is part of Informes plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Informes\Lib\Informes;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\CuentaEspecial;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ResultReport
{
    /** @var string */
    protected static $codejercicio;

    /** @var string */
    protected static $codejercicio_ant;

    /** @var array */
    protected static $gastos;

    /** @var string */
    protected static $last_year;

    /** @var string */
    protected static $parent_codcuenta;

    /** @var string */
    protected static $parent_codfamilia;

    /** @var array */
    protected static $resultado;

    /** @var array */
    protected static $ventas;

    /** @var array */
    protected static $compras;

    /** @var string */
    protected static $year;

    protected static function apply(array $formData): void
    {
        $eje = new Ejercicio();
        $eje->loadFromCode($formData['codejercicio']);

        $year = date('Y', strtotime($eje->fechafin));

        // seleccionamos el año anterior
        self::$codejercicio = '';
        self::$codejercicio_ant = '';
        self::$last_year = '';
        self::$year = '';

        $where = [new DataBaseWhere('idempresa', $eje->idempresa)];
        $orderBy = ['fechainicio' => 'desc'];
        foreach (Ejercicio::all($where, $orderBy, 0, 0) as $eje) {
            if ($eje->codejercicio == $formData['codejercicio'] or date('Y', strtotime($eje->fechafin)) == $year) {
                self::$codejercicio = $eje->codejercicio;
                self::$year = date('Y', strtotime($eje->fechafin));
            } else if (self::$year) {
                self::$codejercicio_ant = $eje->codejercicio;
                self::$last_year = date('Y', strtotime($eje->fechafin));
                break;
            }
        }

        self::$parent_codcuenta = isset($formData['parent_codcuenta']) ? (string)$formData['parent_codcuenta'] : null;
        self::$parent_codfamilia = isset($formData['parent_codfamilia']) ? (string)$formData['parent_codfamilia'] : null;

        // Llamamos a la función que crea los arrays con los datos,
        // pasándole el año seleccionado y el anterior.
        switch ($formData['action']) {
            case 'load-account':
            case 'load-purchases':
                self::purchasesBuildYear(self::$year, self::$codejercicio);
                self::purchasesBuildYear(self::$last_year, self::$codejercicio_ant);
                break;

            case 'load-family-sales':
            case 'load-family-purchases':
            case 'load-sales':
            case 'load-purchases-product':
                self::salesPurchasesBuildYear(self::$year, self::$codejercicio, $formData['action']);
                self::salesPurchasesBuildYear(self::$last_year, self::$codejercicio_ant, $formData['action']);
                break;

            case 'load-summary':
                self::summaryBuildYear(self::$year, self::$codejercicio);
                self::summaryBuildYear(self::$last_year, self::$codejercicio_ant);
                break;
        }
    }

    protected static function build_data($dl): array
    {
        $pvp_total = round($dl['pvptotal'], FS_NF0);
        $referencia = $dl['referencia'];
        $producto = new Producto();
        $variante = new Variante();

        $articulo = false;
        if ($referencia) {
            $where = [new DataBaseWhere('referencia', $referencia)];
            if ($variante->loadFromCode('', $where)) {
                $articulo = true;
                $producto->loadFromCode($variante->idproducto);
                $descripcion = strlen($producto->descripcion) > 50 ? substr($producto->descripcion, 0, 50) . '...' : $producto->descripcion;
                $descripcion = $descripcion != '' ? ' - ' . $descripcion : $descripcion;
                $art_desc = $referencia . $descripcion;
                $codfamilia = $producto->codfamilia;

                if (empty($codfamilia)) {
                    $codfamilia = 'SIN_FAMILIA';
                    $familia = Tools::lang()->trans('no-family');
                } else {
                    $modelFamilia = new Familia();
                    $modelFamilia->loadFromCode($codfamilia);
                    $familia = $modelFamilia->descripcion;
                }
            }
        }

        if (!$articulo) {
            $referencia = 'SIN_REFERENCIA';
            $art_desc = Tools::lang()->trans('no-product-desc');
            $codfamilia = 'SIN_FAMILIA';
            $familia = 'SIN_FAMILIA';
        }

        return [
            'ref' => $referencia,
            'art_desc' => $art_desc,
            'codfamilia' => $codfamilia,
            'familia' => $familia,
            'pvptotal' => $pvp_total
        ];
    }

    protected static function dataInvoices(array $ventas, array $date, string $codejercicio, int $mes, float &$ventas_total_ser_meses, float &$ventas_total_pag_meses, float &$ventas_total_age_meses, $modelFacturas): array
    {
        $where = [
            new DataBaseWhere('fecha', $date['desde'], '>='),
            new DataBaseWhere('fecha', $date['hasta'], '<='),
            new DataBaseWhere('codejercicio', $codejercicio)
        ];

        foreach ($modelFacturas->all($where, [], 0, 0) as $factura) {
            // Series
            if (isset($ventas['total_ser_mes'][$factura->codserie][$mes])) {
                $ventas['total_ser_mes'][$factura->codserie][$mes] += $factura->neto;
            } else {
                $ventas['total_ser_mes'][$factura->codserie][$mes] = $factura->neto;
            }

            if (isset($ventas['total_ser'][$factura->codserie])) {
                $ventas['total_ser'][$factura->codserie] += $factura->neto;
            } else {
                $ventas['total_ser'][$factura->codserie] = $factura->neto;
            }

            $ventas['series'][$factura->codserie][$mes] = ['pvptotal' => $factura->neto];
            $ventas_total_ser_meses = $factura->neto + $ventas_total_ser_meses;

            // Pagos
            if (isset($ventas['total_pag_mes'][$factura->codpago][$mes])) {
                $ventas['total_pag_mes'][$factura->codpago][$mes] += $factura->neto;
            } else {
                $ventas['total_pag_mes'][$factura->codpago][$mes] = $factura->neto;
            }

            if (isset($ventas['total_pag'][$factura->codpago])) {
                $ventas['total_pag'][$factura->codpago] += $factura->neto;
            } else {
                $ventas['total_pag'][$factura->codpago] = $factura->neto;
            }

            $ventas['pagos'][$factura->codpago][$mes] = ['pvptotal' => $factura->neto];
            $ventas_total_pag_meses = $factura->neto + $ventas_total_pag_meses;

            // Agentes
            $codagente = $factura->codagente ?? 'SIN_AGENTE';
            if (isset($ventas['total_age_mes'][$codagente][$mes])) {
                $ventas['total_age_mes'][$codagente][$mes] += $factura->neto;
            } else {
                $ventas['total_age_mes'][$codagente][$mes] = $factura->neto;
            }

            if (isset($ventas['total_age'][$codagente])) {
                $ventas['total_age'][$codagente] += $factura->neto;
            } else {
                $ventas['total_age'][$codagente] = $factura->neto;
            }

            $ventas['agentes'][$codagente][$mes] = ['pvptotal' => $factura->neto];
            $ventas_total_age_meses = $factura->neto + $ventas_total_age_meses;
        }

        return $ventas;
    }

    protected static function daysInMonth($month, $year): int
    {
        // calculate number of days in a month CALC_GREGORIAN
        return $month == 2 ? ($year % 4 ? 28 : ($year % 100 ? 29 : ($year % 400 ? 28 : 29))) : (($month - 1) % 7 % 2 ? 30 : 31);
    }

    protected static function defaultMoney(): string
    {
        return '<span style="color:#ccc;">' . Tools::money(0) . '</span>';
    }

    protected static function defaultPerc(): string
    {
        return '<span style="color:#ccc;">0.0 %</span>';
    }

    protected static function getCuentaGastos(string $codejercicio): Cuenta
    {
        // obtenemos la cuenta especial de COMPRA
        $cuentaEspecial = new CuentaEspecial();
        if (false === $cuentaEspecial->loadFromCode('COMPRA')) {
            return new Cuenta();
        }

        // obtenemos la cuenta de gastos
        $cuenta = $cuentaEspecial->getCuenta($codejercicio);

        // si no existe, terminamos
        if (false === $cuenta->exists()) {
            return new Cuenta();
        }

        // si la cuenta obtenida no tiene cuenta padre, la devolvemos
        if (empty($cuenta->parent_idcuenta)) {
            return $cuenta;
        }

        // si la cuenta tiene cuenta padre, la buscamos
        return self::getCuentaGastosPadre($cuenta->parent_idcuenta);
    }

    protected static function getCuentaGastosPadre(int $id_cuenta): Cuenta
    {
        $cuenta = new Cuenta();
        if (false === $cuenta->loadFromCode($id_cuenta)) {
            return new Cuenta();
        }

        // si no tiene cuenta padre, la devolvemos
        if (empty($cuenta->parent_idcuenta)) {
            return $cuenta;
        }

        // si tiene cuenta padre, la buscamos
        return self::getCuentaGastosPadre($cuenta->parent_idcuenta);
    }

    protected static function purchasesBuildYear($year, $codejercicio): void
    {
        $db = new DataBase();

        $date = [
            'desde' => '',
            'hasta' => '',
        ];

        $gastos = [
            'cuentas' => [],
            'total_cuenta' => [],
            'total_cuenta_mes' => [],
            'total_subcuenta' => [],
            'total_mes' => [],
            'porc_cuenta' => [],
            'porc_subcuenta' => [],
        ];

        $gastos_total_meses = 0;

        // necesitamos el número de meses para calcular la media
        $countMonth = 0;

        // Recorremos los meses y ejecutamos una consulta filtrando por el mes
        for ($mes = 1; $mes <= 12; $mes++) {
            // inicializamos
            $gastos['total_mes'][$mes] = 0;

            if ($year) {
                $dia_mes = ResultReport::daysInMonth($mes, $year);
                $date['desde'] = date('01-' . $mes . '-' . $year);
                $date['hasta'] = date($dia_mes . '-' . $mes . '-' . $year);

                self::setGastos($db, $codejercicio, $date, $mes, $gastos_total_meses, $gastos);

                // Las descripciones solo las necesitamos en el año seleccionado,
                // en el año anterior se omite
                if ($year == self::$year) {
                    $gastos = self::setDescriptionAccount($gastos, $codejercicio);
                }
            }

            if ($gastos['total_mes'][$mes] > 0) {
                $countMonth++;
            }
        }

        /**
         *  TOTALES GLOBALES
         * *****************************************************************
         */
        $gastos['total_mes'][0] = round($gastos_total_meses, FS_NF0);

        if ($countMonth > 0) {
            $gastos['total_mes']['media'] = round($gastos_total_meses / $countMonth, FS_NF0);
        } else {
            $gastos['total_mes']['media'] = round($gastos_total_meses, FS_NF0);
        }

        /**
         *  PORCENTAJES
         * *****************************************************************
         */

        // GASTOS: Calculamos los porcentajes con los totales globales
        $gastos = self::setPercentagePurchases($gastos, $gastos_total_meses);

        // Variables globales para usar en la vista
        self::$gastos[$year] = $gastos;
    }

    protected static function randomColor(): string
    {
        return substr(str_shuffle('ABCDEF0123456789'), 0, 6);
    }

    protected static function setDescriptionAccount(array $gastos, string $codejercicio): array
    {
        // GASTOS: Creamos un array con las descripciones de las cuentas
        foreach ($gastos['cuentas'] as $codcuenta => $arraycuenta) {
            // Añadimos las descripciones de las subcuentas
            // solo al desplegar una cuenta
            if (self::$parent_codcuenta === (string)$codcuenta) {
                $gastos = self::setDescriptionSubaccount($gastos, $arraycuenta, $codejercicio);
                continue;
            }

            $gastos['descripciones'][$codcuenta] = '-';
            $subcuenta = new Subcuenta();
            $where = [
                new DataBaseWhere('codsubcuenta', $arraycuenta['codsubcuenta']),
                new DataBaseWhere('codejercicio', $codejercicio)
            ];
            if (false === $subcuenta->loadFromCode('', $where)) {
                continue;
            }

            $cuenta = new Cuenta();
            $where = [new DataBaseWhere('codcuenta', $subcuenta->codcuenta),];
            if ($cuenta->loadFromCode('', $where)) {
                $gastos['descripciones'][$codcuenta] = $codcuenta . ' - ' . $cuenta->descripcion;
            }
        }

        return $gastos;
    }

    protected static function setDescriptionAgents(array $ventas): array
    {
        foreach ($ventas['agentes'] as $codagente => $agentes) {
            if ($codagente === 'SIN_AGENTE') {
                $ventas['agentes'][$codagente]['descripcion'] = Tools::lang()->trans('no-agent');
                continue;
            }

            // buscamos el agente en la base de datos para asignar el nombre
            $agente = new Agente();
            if ($agente->loadFromCode($codagente)) {
                $ventas['agentes'][$codagente]['descripcion'] = $agente->nombre;
                continue;
            }

            // no lo hemos encontrado, pero por lo menos ponemos el código
            $ventas['agentes'][$codagente]['descripcion'] = $codagente;
        }

        return $ventas;
    }

    protected static function setDescriptionFamilies(array $ventas, string $codejercicio): array
    {
        // Recorremos ventas['familias'] crear un array con las descripciones de las familias
        foreach ($ventas['familias'] as $codfamilia => $familia) {
            foreach ($familia as $referencia => $array) {
                $dl['referencia'] = $referencia;
                $dl['pvptotal'] = 0;
                $data = self::build_data($dl);

                if (self::$parent_codfamilia === (string)$codfamilia) {
                    $ventas = self::setDescriptionProducts($ventas, $referencia, $data['art_desc']);
                } else {
                    $ventas['descripciones'][$codfamilia] = $data['familia'];
                }
            }
        }

        return $ventas;
    }

    protected static function setDescriptionPayments(array $ventas): array
    {
        foreach ($ventas['pagos'] as $codpago => $pagos) {
            $pago = new FormaPago();
            if ($pago->loadFromCode($codpago)) {
                $ventas['pagos'][$codpago]['descripcion'] = $pago->descripcion;
                continue;
            }

            $ventas['pagos'][$codpago]['descripcion'] = $codpago;
        }

        return $ventas;
    }

    protected static function setDescriptionProducts(array $ventas, string $referencia, string $desc): array
    {
        $ventas['descripciones'][$referencia] = $desc;
        return $ventas;
    }

    protected static function setDescriptionSubaccount(array $gastos, array $arraycuenta, string $codejercicio): array
    {
        foreach ($arraycuenta as $codsubcuenta => $arraysubcuenta) {
            $subcuenta = new Subcuenta();
            $where = [
                new DataBaseWhere('codsubcuenta', $codsubcuenta),
                new DataBaseWhere('codejercicio', $codejercicio)
            ];
            if ($subcuenta->loadFromCode('', $where)) {
                $gastos['descripciones'][$codsubcuenta] = $codsubcuenta . ' - ' . $subcuenta->descripcion;
                continue;
            }

            $gastos['descripciones'][$codsubcuenta] = '-';
        }

        return $gastos;
    }

    protected static function setDescriptionSeries(array $ventas): array
    {
        foreach ($ventas['series'] as $codserie => $series) {
            $serie = new Serie();
            if ($serie->loadFromCode($codserie)) {
                $ventas['series'][$codserie]['descripcion'] = $serie->descripcion;
                continue;
            }

            $ventas['series'][$codserie]['descripcion'] = $codserie;
        }

        return $ventas;
    }

    protected static function setGastos(DataBase $db, string $codejercicio, array $date, int $mes, float &$gastos_total_meses, array &$gastos): void
    {
        // si no existe la tabla partidas, no hacemos nada
        if (false === $db->tableExists('partidas')) {
            return;
        }

        // obtenemos la cuenta para gastos
        $cuentaGastos = self::getCuentaGastos($codejercicio);

        // si no existe la cuenta para gastos, no hacemos nada
        if (false === $cuentaGastos->exists()) {
            return;
        }

        /**
         *  GASTOS
         * *****************************************************************
         */
        // Gastos: Consulta de las partidas y asientos del grupo 6 (sin regularizacion)
        $sql = "select * from partidas as par"
            . " LEFT JOIN asientos as asi ON par.idasiento = asi.idasiento"
            . " where asi.fecha >= " . $db->var2str($date['desde'])
            . " AND asi.fecha <= " . $db->var2str($date['hasta'])
            . " AND asi.codejercicio = " . $db->var2str($codejercicio)
            . " AND COALESCE(asi.operacion, '') <> 'R'"
            . " AND codsubcuenta LIKE '" . $cuentaGastos->codcuenta . "%'"
            . " ORDER BY codsubcuenta";

        $partidas = $db->select($sql);
        if (empty($partidas)) {
            return;
        }

        foreach ($partidas as $p) {
            $codsubcuenta = $p['codsubcuenta'];
            $codcuenta = substr($codsubcuenta, 0, 3);
            $pvp_total = (float)$p['debe'] - (float)$p['haber'];

            // Array con los datos a mostrar
            if (isset($gastos['total_cuenta_mes'][$codcuenta][$mes])) {
                $gastos['total_cuenta_mes'][$codcuenta][$mes] += $pvp_total;
            } else {
                $gastos['total_cuenta_mes'][$codcuenta][$mes] = $pvp_total;
            }

            if (isset($gastos['total_cuenta'][$codcuenta])) {
                $gastos['total_cuenta'][$codcuenta] += $pvp_total;
            } else {
                $gastos['total_cuenta'][$codcuenta] = $pvp_total;
            }

            if (isset($gastos['total_mes'][$mes])) {
                $gastos['total_mes'][$mes] += $pvp_total;
            } else {
                $gastos['total_mes'][$mes] = $pvp_total;
            }

            $gastos_total_meses = $pvp_total + $gastos_total_meses;

            if (self::$parent_codcuenta === $codcuenta) {
                if (isset($gastos['total_subcuenta'][$codcuenta][$codsubcuenta])) {
                    $gastos['total_subcuenta'][$codcuenta][$codsubcuenta] += $pvp_total;
                } else {
                    $gastos['total_subcuenta'][$codcuenta][$codsubcuenta] = $pvp_total;
                }

                if (isset($gastos['cuentas'][$codcuenta][$codsubcuenta][$mes])) {
                    $gastos['cuentas'][$codcuenta][$codsubcuenta][$mes]['pvptotal'] += $pvp_total;
                } else {
                    $gastos['cuentas'][$codcuenta][$codsubcuenta][$mes]['pvptotal'] = $pvp_total;
                }
            } else {
                $gastos['cuentas'][$codcuenta]['codsubcuenta'] = $codsubcuenta;
            }
        }
    }

    protected static function setPercentageAgents(array $ventas, float $ventas_total_age_meses): array
    {
        foreach ($ventas['agentes'] as $codagente => $agentes) {
            if ($ventas_total_age_meses != 0) {
                $ventas['porc_age'][$codagente] = round($ventas['total_age'][$codagente] * 100 / $ventas_total_age_meses, FS_NF0);
            }
        }

        return $ventas;
    }

    protected static function setPercentageFamilies(array $ventas, float $ventas_total_fam_meses): array
    {
        foreach ($ventas['familias'] as $codfamilia => $familias) {
            if ($ventas_total_fam_meses != 0) {
                $ventas['porc_fam'][$codfamilia] = round($ventas['total_fam'][$codfamilia] * 100 / $ventas_total_fam_meses, FS_NF0);

                // añadimos los porcentages de los productos
                if (self::$parent_codfamilia === (string)$codfamilia) {
                    $ventas = self::setPercentageProducts($ventas, $codfamilia, $ventas_total_fam_meses, $familias);
                }
            }
        }

        return $ventas;
    }

    protected static function setPercentagePayments(array $ventas, float $ventas_total_pag_meses): array
    {
        foreach ($ventas['pagos'] as $codpago => $pagos) {
            if ($ventas_total_pag_meses != 0) {
                $ventas['porc_pag'][$codpago] = round($ventas['total_pag'][$codpago] * 100 / $ventas_total_pag_meses, FS_NF0);
            }
        }

        return $ventas;
    }

    protected static function setPercentageProducts(array $ventas, string $codfamilia, float $ventas_total_fam_meses, array $familias): array
    {
        foreach ($familias as $referencia => $array) {
            $ventas['porc_ref'][$codfamilia][$referencia] = round($ventas['total_ref'][$codfamilia][$referencia] * 100 / $ventas_total_fam_meses, FS_NF0);
        }

        return $ventas;
    }

    protected static function setPercentageSeries(array $ventas, float $ventas_total_ser_meses): array
    {
        foreach ($ventas['series'] as $codserie => $series) {
            if ($ventas_total_ser_meses != 0) {
                $ventas['porc_ser'][$codserie] = round($ventas['total_ser'][$codserie] * 100 / $ventas_total_ser_meses, FS_NF0);
            }
        }

        return $ventas;
    }

    protected static function setPercentagePurchases(array $gastos, float $gastos_total_meses): array
    {
        foreach ($gastos['cuentas'] as $codcuenta => $cuenta) {
            if ($gastos_total_meses != 0) {
                $gastos['porc_cuenta'][$codcuenta] = round($gastos['total_cuenta'][$codcuenta] * 100 / $gastos_total_meses, FS_NF0);
                if (self::$parent_codcuenta === (string)$codcuenta) {
                    foreach ($cuenta as $codsubcuenta => $subcuenta) {
                        $gastos['porc_subcuenta'][$codcuenta][$codsubcuenta] = round($gastos['total_subcuenta'][$codcuenta][$codsubcuenta] * 100 / $gastos_total_meses, FS_NF0);
                    }
                }
            }
        }

        return $gastos;
    }

    protected static function summaryBuildYear($year, $codejercicio): void
    {
        self::salesPurchasesBuildYear($year, $codejercicio, "load-sales");
        self::salesPurchasesBuildYear($year, $codejercicio, "load-purchases");
        self::purchasesBuildYear($year, $codejercicio);

        $resultado = [
            'total_mes' => [],
        ];

        // Recorremos los meses y ejecutamos una consulta filtrando por el mes
        for ($mes = 1; $mes <= 12; $mes++) {
            // inicializamos
            $resultado['total_mes'][$mes] = 0;

            if (!isset(self::$ventas[$year]['total_mes'][$mes])) {
                self::$ventas[$year]['total_mes'][$mes] = 0;
                self::$ventas[$year]['total_mes']['media'] = 0;
            }

            if (!isset(self::$gastos[$year]['total_mes'][$mes])) {
                self::$gastos[$year]['total_mes'][$mes] = 0;
                self::$gastos[$year]['total_mes']['media'] = 0;
            }

            /**
             *  RESULTADOS
             * *****************************************************************
             */
            $resultado['total_mes'][$mes] = round(self::$ventas[$year]['total_mes'][$mes] - max(self::$gastos[$year]['total_mes'][$mes], self::$compras[$year]['total_mes'][$mes]), FS_NF0);
        }

        if (!isset(self::$ventas[$year]['total_mes'][0])) {
            self::$ventas[$year]['total_mes'][0] = 0;
        }

        if (!isset(self::$gastos[$year]['total_mes'][0])) {
            self::$gastos[$year]['total_mes'][0] = 0;
        }

        /**
         *  TOTALES GLOBALES
         * *****************************************************************
         */
        $resultado['total_mes'][0] = round(self::$ventas[$year]['total_mes'][0] - max(self::$gastos[$year]['total_mes'][0], self::$compras[$year]['total_mes'][0]), FS_NF0);
        $resultado['total_mes']['media'] = round((self::$ventas[$year]['total_mes']['media'] - max(self::$gastos[$year]['total_mes']['media'], self::$compras[$year]['total_mes']['media'])), FS_NF0);

        // Variables globales para usar en la vista
        self::$resultado[$year] = $resultado;
    }

    protected static function salesPurchasesBuildYear(string $year, string $codejercicio, string $action): void
    {
        $key = ($action == "load-sales" or $action == "load-family-sales") ? "ventas" : "compras";

        $date = [
            'desde' => '',
            'hasta' => '',
        ];

        ${$key} = [
            'agentes' => [],
            'descripciones' => [],
            'familias' => [],
            'pagos' => [],
            'series' => [],
            'total_fam' => [],
            'total_ser' => [],
            'total_pag' => [],
            'total_age' => [],
            'total_fam_mes' => [],
            'total_ser_mes' => [],
            'total_pag_mes' => [],
            'total_age_mes' => [],
            'total_ref' => [],
            'total_mes' => [],
            'porc_fam' => [],
            'porc_ser' => [],
            'porc_pag' => [],
            'porc_age' => [],
            'porc_ref' => [],
        ];

        $ventas_total_fam_meses = 0;
        $ventas_total_ser_meses = 0;
        $ventas_total_pag_meses = 0;
        $ventas_total_age_meses = 0;

        // necesitamos el número de meses para calcular la media
        $countMonth = 0;

        // Recorremos los meses y ejecutamos una consulta filtrando por el mes
        for ($mes = 1; $mes <= 12; $mes++) {
            // inicializamos
            ${$key}['total_mes'][$mes] = 0;

            if ($year) {
                $dia_mes = ResultReport::daysInMonth($mes, $year);
                $date['desde'] = date('01-' . $mes . '-' . $year);
                $date['hasta'] = date($dia_mes . '-' . $mes . '-' . $year);

                /**
                 *  VENTAS: Consulta con las lineasfacturascli
                 *  COMPRAS: Consulta con las lineasfacturasprov
                 * *****************************************************************
                 */

                $table_name = ($action == "load-sales" or $action == "load-family-sales") ? "facturascli" : "facturasprov";
                $model = ($action == "load-sales" or $action == "load-family-sales") ? new FacturaCliente() : new FacturaProveedor();


                ${$key} = self::invoiceLines(${$key}, $date, $codejercicio, $mes, $ventas_total_fam_meses, $countMonth, $table_name);
                // Recorremos las facturas
                ${$key} = self::dataInvoices(${$key}, $date, $codejercicio, $mes, $ventas_total_ser_meses, $ventas_total_pag_meses, $ventas_total_age_meses, $model);


                // Las descripciones solo las necesitamos en el año seleccionado,
                // en el año anterior se omite
                if ($year == self::$year) {
                    ${$key} = self::setDescriptionFamilies(${$key}, $codejercicio);
                    ${$key} = self::setDescriptionSeries(${$key});
                    ${$key} = self::setDescriptionPayments(${$key});
                    ${$key} = self::setDescriptionAgents(${$key});
                }
            }
        }

        /**
         *  TOTALES GLOBALES
         * *****************************************************************
         */
        ${$key}['total_mes'][0] = round($ventas_total_fam_meses, FS_NF0);

        if ($countMonth > 0) {
            ${$key}['total_mes']['media'] = round($ventas_total_fam_meses / $countMonth, FS_NF0);
        } else {
            ${$key}['total_mes']['media'] = round($ventas_total_fam_meses, FS_NF0);
        }

        /**
         *  PORCENTAJES
         * *****************************************************************
         */
        // VENTAS: Calculamos los porcentajes con los totales globales
        ${$key} = self::setPercentageFamilies(${$key}, $ventas_total_fam_meses);
        ${$key} = self::setPercentageSeries(${$key}, $ventas_total_ser_meses);
        ${$key} = self::setPercentagePayments(${$key}, $ventas_total_pag_meses);
        ${$key} = self::setPercentageAgents(${$key}, $ventas_total_age_meses);

        // Variables globales para usar en la vista
        self::${$key}[$year] = ${$key};
    }

    protected static function invoiceLines(array $ventas, array $date, string $codejercicio, int $mes, float &$ventas_total_fam_meses, int &$countMonth, string $tablename): array
    {
        $db = new DataBase();
        $sql = "select lfc.referencia, sum(lfc.pvptotal) as pvptotal from lineas{$tablename} as lfc"
            . " LEFT JOIN {$tablename} as fc ON lfc.idfactura = fc.idfactura"
            . " where fc.fecha >= " . $db->var2str($date['desde'])
            . " AND fc.fecha <= " . $db->var2str($date['hasta'])
            . " AND fc.codejercicio = " . $db->var2str($codejercicio)
            . " group by lfc.referencia";

        // VENTAS: Recorremos lineasfacturascli y montamos arrays
        $lineas = $db->select($sql);
        foreach ($lineas as $dl) {
            $data = self::build_data($dl);
            $pvp_total = (float)$data['pvptotal'];
            $referencia = $data['ref'];
            $codfamilia = $data['codfamilia'];

            // Familias
            if (isset($ventas['total_fam_mes'][$codfamilia][$mes])) {
                $ventas['total_fam_mes'][$codfamilia][$mes] += $pvp_total;
            } else {
                $ventas['total_fam_mes'][$codfamilia][$mes] = $pvp_total;
            }

            if (isset($ventas['total_fam'][$codfamilia])) {
                $ventas['total_fam'][$codfamilia] += $pvp_total;
            } else {
                $ventas['total_fam'][$codfamilia] = $pvp_total;
            }

            // Solo al pinchar en una familia
            if (self::$parent_codfamilia === (string)$codfamilia) {
                // Productos
                if (isset($ventas['total_ref'][$codfamilia][$referencia])) {
                    $ventas['total_ref'][$codfamilia][$referencia] += $pvp_total;
                } else {
                    $ventas['total_ref'][$codfamilia][$referencia] = $pvp_total;
                }

                if (isset($ventas['familias'][$codfamilia][$referencia][$mes])) {
                    $ventas['familias'][$codfamilia][$referencia][$mes]['pvptotal'] += $pvp_total;
                } else {
                    $ventas['familias'][$codfamilia][$referencia][$mes]['pvptotal'] = $pvp_total;
                }
            }

            // Totales
            $ventas['total_mes'][$mes] = $pvp_total + $ventas['total_mes'][$mes];
            $ventas_total_fam_meses = $pvp_total + $ventas_total_fam_meses;

            // Array temporal con los totales (falta añadir descripción familia)
            $ventas['familias'][$codfamilia][$referencia][$mes] = ['pvptotal' => $pvp_total];
        }

        if ($ventas['total_mes'][$mes] > 0) {
            $countMonth++;
        }

        return $ventas;
    }
}
