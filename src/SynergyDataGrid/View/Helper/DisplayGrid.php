<?php
    namespace SynergyDataGrid\View\Helper;

    /**
     * This file is part of the Synergy package.
     *
     * (c) Pele Odiase <info@rhemastudio.com>
     *
     * For the full copyright and license information, please view the LICENSE
     * file that was distributed with this source code.
     *
     * @author  Pele Odiase
     * @license http://opensource.org/licenses/BSD-3-Clause
     *
     */
    use SynergyDataGrid\Grid\GridType\BaseGrid;
    use SynergyDataGrid\Grid\Plugin\DatePicker;
    use SynergyDataGrid\Grid\SubGridAwareInterface;
    use SynergyDataGrid\Grid\Toolbar;
    use Zend\Http\PhpEnvironment\Request;
    use Zend\Json\Expr;
    use Zend\Stdlib\Parameters;
    use Zend\View\Helper\AbstractHelper;
    use Zend\Json\Json;

    /**
     * View Helper to render jqGrid control
     *
     * @author  Pele Odiase
     * @package mvcgrid
     */
    class DisplayGrid extends AbstractHelper
    {
        /**
         * @param BaseGrid $grid
         * @param bool     $appendScript
         *
         * @return mixed
         */
        public function __invoke(BaseGrid $grid, $appendScript = true)
        {
            list($onLoad, $js, $html) = $this->initGrid($grid);
            $config = $grid->getConfig();

            $onLoadScript = ';jQuery(function(){' . $onLoad . '});';
            $js           = 'function synergyResizeGrid(grid, parentSelector){
                                var g = jQuery(grid);
                                var par = g.closest(parentSelector);
                                var padding = g.data("padding");
                                var gw = par.innerWidth() - padding;
                                g.jqGrid("setGridWidth",gw);
                            }
                            var synergyDataGrid = { ' . DatePicker::DATE_PICKER_FUNCTION . ' : []}; ' . $js;

            if ($appendScript) {
                if ($config['render_script_as_template']) {
                    $this->getView()->headScript()
                        ->appendScript($onLoadScript, 'text/x-jquery-tmpl', array("id='grid-script'", 'noescape' => true))
                        ->appendScript($js);
                } else {
                    $this->getView()->headScript()
                        ->appendScript($onLoadScript)
                        ->appendScript($js);
                }

                return $html;
            } else {
                return array(
                    'html'   => $html,
                    'js'     => $js,
                    'onLoad' => $onLoad
                );
            }

        }

        public function initGrid(BaseGrid $grid)
        {
            $html        = array();
            $js          = array();
            $onLoad      = array();
            $postCommand = array();

            $config = $grid->getConfig();
            $gridId = $grid->getId();

            $grid->setActionsColumn($config['add_action_column']);

            $grid->setGridColumns()
                ->setGridDisplayOptions()
                ->setAllowEditForm($config['allow_form_edit']);

            $onLoad[] = 'var ' . $grid->getLastSelectVariable() . '; ';
            $onLoad[] = sprintf('var %s = jQuery("#%s").addClass("synergy-grid").data("padding", %d);',
                $gridId, $gridId, $grid->getJsCode()->getPadding());
            $onLoad[] = sprintf('%s.parent().addClass("%s");', $gridId, $grid->getJsCode()->getContainerClass());
            $onLoad[] = sprintf('%s.data("lastsel", 0);', $gridId);

            if (!$grid->getEditurl()) {
                $grid->setEditurl($grid->getUrl());
            }

            //add custom toolbar buttons
            list($toolbarEnabled, $toolbarPosition) = $grid->getToolbar();

            if (!$showToolbar = ($config['toolbar_buttons']['global']
                or isset($config['toolbar_buttons']['specific'][$grid->getEntityId()]))
            ) {
                $grid->setToolbar(false);
            }

            //get previous sort order from cookie if set
            $grid->prepareSorting();

            //get number per page if set in cookie
            $grid->preparePaging();

            //load first data for main grid and not subgrids
            if ($config['first_data_as_local'] and !$grid->getIsDetailGrid()) {
                $grid->setDatatype('local');

                $gridOptions = $grid->getOptions();
                $params      = array(
                    'page'                  => 1,
                    'rows'                  => $gridOptions['rowNum'],
                    $grid::GRID_IDENTIFIER  => $grid->getId(),
                    $grid::ENTITY_IDENTFIER => $grid->getEntity()
                );

                if ($grid->getIsTreeGrid()) {
                    $grid->setSortname('lft');
                    $params['displayTree'] = true;
                    if (isset($gridOptions['postData'])) {
                        $params = array_merge($params, $gridOptions['postData']);
                    }
                }

                $request    = new Request();
                $parameters = new Parameters($params);
                $request->setPost($parameters);

                $initialData = $grid->getFirstDataAsLocal($request, true);

                $postCommand[] = sprintf('%s.jqGrid("setGridParam", {datatype:"json", treedatatype : "json"});',
                    $gridId, $grid->getEditurl());
                $postCommand[] = sprintf('%s[0].addJSONData(%s) ;', $gridId, Json::encode($initialData));

            }

            $grid->getJsCode()->prepareAfterInsertRow();
            $grid->getJsCode()->prepareAfterSaveRow();
            $grid->getJsCode()->prepareOnEditRow();
            $grid->getJsCode()->prepareAfterRestoreRow();

            if ($grid->getAllowResizeColumns()) {
                $grid->prepareColumnSizes();
            }

            //Add subgrid as grid data. This will override any subgrid
            if ($grid instanceof SubGridAwareInterface and  $subGrids = $grid->getSubGridsAsGrid()) {

                foreach ($subGrids as $subGrid) {
                    list($l[], $s[], $h[]) = $this->initGrid($subGrid);
                }

                $expandFunction = new Expr(
                    sprintf("function(subgrid_id, row_id) {
                       jQuery('#'+subgrid_id).html('%s');
                       %s
                       %s
                    }"
                        ,
                        implode("<hr />", $h),
                        implode("\n", $l),
                        implode("\n", $s)
                    )
                );

                $grid->setSubGridRowExpanded($expandFunction);
            }

            $onLoad[] = $grid->getJsCode()->prepareSetColumnsOrderingCookie();
            $grid->reorderColumns();

            $onLoad[] = sprintf('%s.jqGrid(%s);', $gridId,
                Json::encode($grid->getOptions(), false, array('enableJsonExprFinder' => true)));

            $datePicker = $grid->getDatePicker()->prepareDatepicker();
            $js         = array_merge($js, $datePicker);

            $html[] = '<table id="' . $gridId . '"></table>';
            if ($grid->getNavGridEnabled()) {
                if ($grid->getIsDetailGrid()) {
                    $grid->getNavGrid()->setSearch(false);
                }

                $options   = $grid->getNavGrid()->getOptions() ? : new \stdClass();
                $prmEdit   = $grid->getNavGrid()->getEditParameters() ? : new \stdClass();
                $prmAdd    = $grid->getNavGrid()->getAddParameters() ? : new \stdClass();
                $prmDel    = $grid->getNavGrid()->getDelParameters() ? : new \stdClass();
                $prmSearch = $grid->getNavGrid()->getSearchParameters() ? : new \stdClass();
                $prmView   = $grid->getNavGrid()->getViewParameters() ? : new \stdClass();

                $jsPager = sprintf('%s.jqGrid("navGrid","#%s",%s,%s,%s,%s,%s,%s);',
                    $gridId,
                    $grid->getPager(),
                    Json::encode($options, false, array('enableJsonExprFinder' => true)),
                    Json::encode($prmEdit, false, array('enableJsonExprFinder' => true)),
                    Json::encode($prmAdd, false, array('enableJsonExprFinder' => true)),
                    Json::encode($prmDel, false, array('enableJsonExprFinder' => true)),
                    Json::encode($prmSearch, false, array('enableJsonExprFinder' => true)),
                    Json::encode($prmView, false, array('enableJsonExprFinder' => true))
                );


                //display filter toolbar
                if ($config['filter_toolbar']['enabled']) {
                    $onLoad[] = sprintf('%s.jqGrid("filterToolbar",%s);',
                        $gridId,
                        Json::encode($config['filter_toolbar']['options'], false, array('enableJsonExprFinder' => true))
                    );

                    if (!$config['filter_toolbar']['showOnLoad']) {
                        $onLoad[] = sprintf('%s[0].toggleToolbar();', $gridId);
                    }
                }

                $navButtons = $grid->getNavButtons();

                if (is_array($navButtons)) {
                    foreach ($navButtons as $title => $button) {
                        $jsPager .= sprintf('%s.navButtonAdd("#%s",{
                            caption: "%s",
                            title: "%s",
                            buttonicon: "%s",
                            onClickButton: %s,
                            position: "%s",
                            cursor: "%s",
                            id: "%s"
                            });
                        ',
                            $gridId,
                            $grid->getPager(),
                            $button['caption'],
                            $title,
                            $button['icon'],
                            $button['action'],
                            $button['position'],
                            $button['cursor'],
                            $button['id']
                        );
                    }
                }
                $htmlPager = '<div id="' . $grid->getPager() . '"></div>';
            }

            $onLoad[] = $jsPager;
            $html[]   = $htmlPager;

            //setup inline navigation
            if ($grid->getInlineNavEnabled() and $grid->getInlineNav()) {
                $jsInline = sprintf('%s.jqGrid("inlineNav", "#%s",%s)',
                    $gridId,
                    $grid->getPager(),
                    Json::encode($grid->getInlineNav()->getOptions(), false, array('enableJsonExprFinder' => true))
                );
                $jsInline .= ';';
                $onLoad[] = $jsInline;
                $onLoad[] = $grid->getProcessAfterSubmit();
                if (!$htmlPager) {
                    $html[] = '<div id="' . $grid->getPager() . '"></div>';
                }
            }

            //add custom toolbar buttons
            if ($showToolbar) {
                if ($toolbarPosition == Toolbar::POSITION_BOTH) {
                    $toolbars[] = new Toolbar($grid, $config['toolbar_buttons'], Toolbar::POSITION_BOTTOM, $toolbarPosition);
                    $toolbars[] = new Toolbar($grid, $config['toolbar_buttons'], Toolbar::POSITION_TOP, $toolbarPosition);

                } elseif ($toolbarPosition == Toolbar::POSITION_BOTTOM) {
                    $toolbars[] = new Toolbar($grid, $config['toolbar_buttons'], Toolbar::POSITION_BOTTOM, $toolbarPosition);
                } else {
                    $toolbars[] = new Toolbar($grid, $config['toolbar_buttons'], Toolbar::POSITION_TOP, $toolbarPosition);
                }

                /** @var $toolbarButton \SynergyDataGrid\Grid\Toolbar\Item */
                foreach ($toolbars as $toolbar) {
                    $toolbarId       = $toolbar->getId();
                    $toolbarPosition = $toolbar->getPosition();
                    $onLoad[]        = sprintf("var %s = jQuery('#%s');", $toolbarId, $toolbarId);
                    $onLoad[]        = sprintf(";%s.data('grid', %s).addClass('grid-toolbar btn-group grid-toolbar-%s');",
                        $toolbarId, $gridId, $toolbarPosition);

                    foreach ($toolbar->getItems() as $toolbarButton) {
                        $buttonPosition = $toolbarButton->getPosition();
                        if ($buttonPosition == Toolbar::POSITION_BOTH
                            or $buttonPosition == $toolbarPosition
                        ) {
                            $onLoad[] = sprintf("%s.append(\"<button data-toolbar-id='%s' id='%s' title='%s' class='%s' %s><i class='icon %s'></i> %s</button>\");
                                        jQuery('#%s', '#%s').bind('click', %s);",
                                $toolbarId,
                                $toolbarId,
                                $toolbarButton->getId(),
                                $toolbarButton->getTitle(),
                                $toolbarButton->getClass(),
                                $toolbarButton->getAttributes(),
                                $toolbarButton->getIcon(),
                                $toolbarButton->getTitle(),
                                $toolbarButton->getId(),
                                $toolbar->getId(),
                                Json::encode($toolbarButton->getCallback(), false, array('enableJsonExprFinder' => true))
                            );

                            if ($init = $toolbarButton->getOnLoad()) {
                                $onLoad[] = Json::encode($init, false, array('enableJsonExprFinder' => true));
                            }
                        }
                    }
                }
            }

            $onLoad = array_merge($onLoad, $postCommand);
            $onLoad = array_filter($onLoad);

            foreach ($grid->getJsCode()->getCustomScripts() as $script) {
                $onLoad[] = Json::encode($script, false, array('enableJsonExprFinder' => true));
            }

            $onLoad[] = sprintf("; synergyResizeGrid('#%s', '.%s');", $grid->getId(), $grid->getJsCode()->getContainerClass());

            $html   = array_merge($html, $grid->getHtml());
            $js     = array_merge($js, $grid->getJs());
            $onLoad = array_merge($onLoad, $grid->getOnload());

            if ($config['compress_script']) {
                $onLoad = $this->compressJavaScriptScript($onLoad);
                $js     = $this->compressJavaScriptScript($js);
            }

            return array(
                implode("\n", $onLoad),
                implode("\n", $js),
                implode("", $html)
            );
        }

        /**
         * Compress javascript code, removes whitespaces
         *
         * @param $script
         *
         * @return mixed
         */
        protected function compressJavaScriptScript($script)
        {
            $regex = array(
                "/(>|;|\"|\}|\,|\{|\.|\'|\|)( *)?(\r\n|\s)+/" => "$1 ",
                "/(\r\n){2,}/"                                => "\r\n", "/(\t| {2,})/" => ' '
            );

            return preg_replace(array_keys($regex), array_values($regex), $script);
        }
    }