{{-- One tile of the grid.

     A partial for the same reason as the tree: the picker dialog shows the
     same tiles, and a tile drawn twice is a badge that ends up
     in one of the two only. The template loops over `items` of the
     component it stands inside. --}}
                    <template x-for="(item, index) in items" x-bind:key="item.id">
                        <div
                            class="cms-tile"
                            role="option"
                            tabindex="0"
                            draggable="true"
                            x-bind:data-cms-media="item.id"
                            x-bind:aria-selected="isSelected(item.id)"
                            x-bind:aria-label="item.name"
                            x-bind:class="{ '_is-selected': isSelected(item.id), '_is-open': details && details.id === item.id }"
                            x-on:click="openDetails(item, $event)"
                            {{-- What a second click means belongs to the
                                 screen: the section opens nothing twice, the
                                 picker takes the file and closes. --}}
                            x-on:dblclick.stop="confirmTile(item)"
                            x-on:dragstart="startDrag(item, $event)"
                            x-on:dragend="endDrag()"
                        >
                            <div class="cms-tile__preview" x-bind:class="item.kind === 'vector' && '_is-vector'">
                                <template x-if="item.kind === 'image' && item.thumb_url">
                                    <img class="cms-tile__img" x-bind:src="item.thumb_url" alt="" loading="lazy">
                                </template>
                                <template x-if="item.kind === 'vector'">
                                    <img class="cms-tile__img _is-contain" x-bind:src="item.url" alt="" loading="lazy">
                                </template>
                                {{-- A video whose frame is cut shows the frame, marked as
                                     a video so it is never taken for a picture. --}}
                                <template x-if="item.kind === 'video' && item.thumb_url">
                                    <span class="cms-tile__frame">
                                        <img class="cms-tile__img" x-bind:src="item.thumb_url" alt="" loading="lazy">
                                        <span class="cms-tile__kind">
                                            @include('moonshine-media::components.media-kind-icon', ['expr' => 'item.kind', 'size' => '4'])
                                        </span>
                                    </span>
                                </template>
                                <template x-if="item.kind !== 'image' && item.kind !== 'vector' && ! (item.kind === 'video' && item.thumb_url)">
                                    <span class="cms-tile__glyph">
                                        @include('moonshine-media::components.media-kind-icon', ['expr' => 'item.kind', 'size' => '10'])
                                        <span class="cms-tile__ext" x-text="item.ext"></span>
                                    </span>
                                </template>

                                <button
                                    type="button"
                                    class="cms-tile__check"
                                    tabindex="-1"
                                    x-bind:class="isSelected(item.id) && '_is-on'"
                                    x-bind:aria-label="labels.selection.select"
                                    x-on:click.stop="toggle(item, index, $event)"
                                ><x-moonshine::icon icon="check" size="4" /></button>
                            </div>

                            <div class="cms-tile__foot">
                                <span class="cms-tile__name" x-bind:title="item.name" x-text="shorten(item.name)"></span>
                                <span class="cms-tile__size" x-text="item.size_human"></span>
                                <span class="cms-tile__badges">
                                    <template x-if="! item.has_alt">
                                        <span class="cms-lib-badge _is-warn" x-text="labels.badges.no_alt"></span>
                                    </template>
                                    <template x-if="item.usages_count === 0">
                                        <span class="cms-lib-badge" x-text="labels.badges.unused"></span>
                                    </template>
                                </span>
                            </div>
                        </div>
                    </template>
