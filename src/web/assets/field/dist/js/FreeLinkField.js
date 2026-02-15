(function() {
    'use strict';

    /**
     * FreeLink field controller.
     * Uses Craft's Garnish library for drag-and-drop sorting.
     */
    if (typeof Craft === 'undefined') {
        return;
    }

    Craft.FreeLinkField = Garnish.Base.extend({
        $container: null,
        $linksContainer: null,
        $addBtn: null,
        fieldName: null,
        settings: null,
        linkBlocks: [],
        sortable: null,

        init: function(container, settings) {
            this.$container = $(container);
            this.settings = settings || {};
            this.fieldName = this.settings.fieldName || '';
            this.$linksContainer = this.$container.find('.freelink-links');
            this.$addBtn = this.$container.find('.freelink-add-btn');
            this.linkBlocks = [];

            // Initialize existing link blocks
            var self = this;
            this.$linksContainer.find('.freelink-link-block').each(function(index) {
                self._initBlock($(this), index);
            });

            // Add button
            if (this.$addBtn.length) {
                this.addListener(this.$addBtn, 'click', '_onAddClick');
            }

            // Initialize sortable for multi-link mode
            if (this.settings.multipleLinks) {
                this._initSortable();
            }
        },

        _initBlock: function($block, index) {
            var block = {
                $block: $block,
                $typeSelect: $block.find('.freelink-type-select'),
                $typeContainers: $block.find('.freelink-type-input'),
                $removeBtn: $block.find('.freelink-remove-btn'),
                $advancedToggle: $block.find('.freelink-advanced-toggle'),
                $advancedBody: $block.find('.freelink-advanced-body'),
                index: index
            };

            var self = this;

            // Type switcher
            if (block.$typeSelect.length) {
                block.$typeSelect.on('change', function() {
                    self._onTypeChange(block, $(this).val());
                });
            }

            // Remove button
            if (block.$removeBtn.length) {
                block.$removeBtn.on('click', function() {
                    self._onRemoveClick(block);
                });
            }

            // Advanced toggle
            if (block.$advancedToggle.length) {
                block.$advancedToggle.on('click', function() {
                    block.$advancedBody.toggleClass('hidden');
                    $(this).attr('aria-expanded',
                        block.$advancedBody.hasClass('hidden') ? 'false' : 'true'
                    );
                });
            }

            this.linkBlocks.push(block);
        },

        _onTypeChange: function(block, newType) {
            // Hide all type inputs, show selected
            block.$typeContainers.addClass('hidden');
            block.$block.find('.freelink-type-input[data-type="' + newType + '"]')
                .removeClass('hidden');
        },

        _onAddClick: function(e) {
            e.preventDefault();

            if (this.settings.maxLinks && this.linkBlocks.length >= this.settings.maxLinks) {
                Craft.cp.displayNotice(
                    Craft.t('freelink', 'Maximum of {max} links reached.', {
                        max: this.settings.maxLinks
                    })
                );
                return;
            }

            var newIndex = this.linkBlocks.length;
            var defaultType = this.settings.defaultLinkType || 'url';

            // Clone the template block
            var $template = this.$container.find('.freelink-link-template');
            if (!$template.length) {
                return;
            }

            var html = $template.html()
                .replace(/__INDEX__/g, newIndex);

            var $newBlock = $(html);
            this.$linksContainer.append($newBlock);
            this._initBlock($newBlock, newIndex);

            // Initialize any element selectors in the new block
            Craft.initUiElements($newBlock);

            this._updateAddButton();

            if (this.sortable) {
                this.sortable.addItems($newBlock);
            }
        },

        _onRemoveClick: function(block) {
            if (this.settings.minLinks && this.linkBlocks.length <= this.settings.minLinks) {
                Craft.cp.displayNotice(
                    Craft.t('freelink', 'Minimum of {min} links required.', {
                        min: this.settings.minLinks
                    })
                );
                return;
            }

            block.$block.remove();
            this.linkBlocks = this.linkBlocks.filter(function(b) {
                return b !== block;
            });

            this._reindexBlocks();
            this._updateAddButton();
        },

        _reindexBlocks: function() {
            this.$linksContainer.find('.freelink-link-block').each(function(index) {
                $(this).find('[name]').each(function() {
                    var name = $(this).attr('name');
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                });
            });
        },

        _updateAddButton: function() {
            if (!this.settings.multipleLinks || !this.$addBtn.length) {
                return;
            }

            if (this.settings.maxLinks && this.linkBlocks.length >= this.settings.maxLinks) {
                this.$addBtn.addClass('hidden');
            } else {
                this.$addBtn.removeClass('hidden');
            }
        },

        _initSortable: function() {
            if (!this.$linksContainer.find('.freelink-link-block').length) {
                return;
            }

            this.sortable = new Garnish.DragSort(
                this.$linksContainer.find('.freelink-link-block'), {
                    handle: '.freelink-drag-handle',
                    axis: 'y',
                    onSortChange: $.proxy(this, '_reindexBlocks')
                }
            );
        }
    });

    // Auto-initialize on page load
    Garnish.$doc.ready(function() {
        $('.freelink-field').each(function() {
            var $field = $(this);
            var settings = $field.data('freelink-settings');
            if (settings && !$field.data('freelink-initialized')) {
                new Craft.FreeLinkField(this, settings);
                $field.data('freelink-initialized', true);
            }
        });
    });

})();
