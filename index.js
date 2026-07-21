panel.plugin('open-foundations/kirby-base', {
  fields: {
    // Renders identically to the core users field; all the narrowed
    // full-name search lives in the PHP UserNamePicker behind its api.
    usernamesearch: {
      extends: 'k-users-field'
    },
    maplocation: {
      props: {
        value: {
          type: [Object, String],
          default: function () { return null; }
        }
      },

      data: function () {
        return {
          lat: '',
          lon: '',
          zoom: 13,
          geocodeQuery: '',
          geocodeResults: [],
          geocodeError: '',
          isGeocoding: false,
          hoveredGeoIndex: -1,
          map: null,
          mapMarker: null,
          mapId: 'bsbi-maploc-' + Math.random().toString(36).substr(2, 9)
        };
      },

      computed: {
        hasCoords: function () {
          var lat = parseFloat(this.lat);
          var lon = parseFloat(this.lon);
          return !isNaN(lat) && !isNaN(lon) && this.lat !== '' && this.lon !== '';
        },
        displayCoords: function () {
          if (!this.hasCoords) return 'No location set';
          return parseFloat(this.lat).toFixed(6) + ', ' + parseFloat(this.lon).toFixed(6);
        }
      },

      watch: {
        value: {
          immediate: true,
          handler: function (val) {
            var lat = '', lon = '', zoom = 13;
            if (val && typeof val === 'object') {
              lat  = val.lat  !== undefined ? String(val.lat)  : '';
              lon  = val.lon  !== undefined ? String(val.lon)  : '';
              zoom = val.zoom || 13;
            } else if (typeof val === 'string' && val.trim() !== '') {
              // Fallback: parse raw YAML string (locator plugin stores this format)
              val.split('\n').forEach(function (line) {
                var colon = line.indexOf(':');
                if (colon > 0) {
                  var key = line.substring(0, colon).trim();
                  var value = line.substring(colon + 1).trim();
                  if (key === 'lat') lat = value;
                  else if (key === 'lon') lon = value;
                  else if (key === 'zoom') zoom = parseInt(value) || 13;
                }
              });
            }
            this.lat = lat; this.lon = lon; this.zoom = zoom;
            this.syncMapToCoords();
          }
        }
      },

      mounted: function () {
        var self = this;
        self.ensureLeaflet().then(function () {
          self.initMap();
        });
      },

      beforeDestroy: function () {
        this.destroyMap();
      },

      methods: {
        ensureLeaflet: function () {
          if (typeof window.L !== 'undefined') {
            return Promise.resolve();
          }
          return new Promise(function (resolve, reject) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
            var script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
          });
        },

        initMap: function () {
          var self = this;
          var el = document.getElementById(self.mapId);
          if (!el || typeof L === 'undefined') return;
          self.destroyMap();
          var lat = parseFloat(self.lat);
          var lon = parseFloat(self.lon);
          var hasCoords = !isNaN(lat) && !isNaN(lon) && self.lat !== '' && self.lon !== '';
          self.map = L.map(el).setView(
            hasCoords ? [lat, lon] : [54.5, -4],
            hasCoords ? (self.zoom || 13) : 5
          );
          var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
          });
          var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { attribution: 'Tiles &copy; Esri', maxZoom: 18 }
          );
          osm.addTo(self.map);
          L.control.layers({ 'Map': osm, 'Satellite': satellite }).addTo(self.map);
          if (!document.getElementById('bsbi-leaflet-layers-fix')) {
            var style = document.createElement('style');
            style.id = 'bsbi-leaflet-layers-fix';
            style.textContent = '.leaflet-control-layers-toggle{background-image:none!important;}.leaflet-control-layers-toggle::before{content:"☰";font-size:20px;line-height:36px;display:block;text-align:center;color:#333;}';
            document.head.appendChild(style);
          }
          if (hasCoords) {
            self.addDraggableMarker(lat, lon);
          }
          self.map.on('click', function (e) {
            self.lat  = e.latlng.lat.toFixed(7);
            self.lon  = e.latlng.lng.toFixed(7);
            self.zoom = self.map.getZoom();
            if (self.mapMarker) {
              self.mapMarker.setLatLng(e.latlng);
            } else {
              self.addDraggableMarker(e.latlng.lat, e.latlng.lng);
            }
            self.emitValue();
          });
          self.map.on('zoomend', function () {
            self.zoom = self.map.getZoom();
            if (self.hasCoords) { self.emitValue(); }
          });
          setTimeout(function () {
            if (self.map) { self.map.invalidateSize(); }
          }, 100);
        },

        destroyMap: function () {
          if (this.map) {
            this.map.remove();
            this.map = null;
            this.mapMarker = null;
          }
        },

        markerIcon: function () {
          return L.divIcon({
            className: '',
            html: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="36" viewBox="0 0 24 36"><path d="M12 0C5.373 0 0 5.373 0 12c0 9 12 24 12 24S24 21 24 12C24 5.373 18.627 0 12 0z" fill="#2563eb" stroke="#1d4ed8" stroke-width="1"/><circle cx="12" cy="12" r="5" fill="white"/></svg>',
            iconSize: [24, 36],
            iconAnchor: [12, 36],
            popupAnchor: [0, -36]
          });
        },

        addDraggableMarker: function (lat, lon) {
          var self = this;
          if (!self.map) return;
          self.mapMarker = L.marker([lat, lon], { draggable: true, icon: self.markerIcon() }).addTo(self.map);
          self.mapMarker.on('dragend', function () {
            var pos = self.mapMarker.getLatLng();
            self.lat = pos.lat.toFixed(7);
            self.lon = pos.lng.toFixed(7);
            self.emitValue();
          });
        },

        syncMapToCoords: function () {
          if (!this.map) return;
          var lat = parseFloat(this.lat);
          var lon = parseFloat(this.lon);
          if (isNaN(lat) || isNaN(lon) || this.lat === '' || this.lon === '') return;
          if (this.mapMarker) {
            this.mapMarker.setLatLng([lat, lon]);
          } else {
            this.addDraggableMarker(lat, lon);
          }
          this.map.setView([lat, lon], this.zoom || this.map.getZoom() || 13);
        },

        geocode: function () {
          var self = this;
          if (!self.geocodeQuery) return;
          self.isGeocoding    = true;
          self.geocodeError   = '';
          self.geocodeResults = [];
          var url = 'https://nominatim.openstreetmap.org/search' +
            '?q=' + encodeURIComponent(self.geocodeQuery) +
            '&format=json&limit=5&addressdetails=0';
          fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              self.geocodeResults = (data || []).map(function (item) {
                return {
                  lat:         String(item.lat          || ''),
                  lon:         String(item.lon          || ''),
                  displayName: String(item.display_name || '')
                };
              });
              self.isGeocoding = false;
              if (self.geocodeResults.length === 0) {
                self.geocodeError = 'No results found. Try a more specific address.';
              }
            })
            .catch(function () {
              self.isGeocoding  = false;
              self.geocodeError = 'Geocoding failed.';
            });
        },

        selectGeocode: function (result) {
          this.lat  = result.lat;
          this.lon  = result.lon;
          this.zoom = 13;
          this.geocodeResults = [];
          this.geocodeError   = '';
          this.geocodeQuery   = '';
          if (this.map) {
            var lat = parseFloat(result.lat);
            var lon = parseFloat(result.lon);
            this.map.setView([lat, lon], 13);
            if (this.mapMarker) {
              this.mapMarker.setLatLng([lat, lon]);
            } else {
              this.addDraggableMarker(lat, lon);
            }
          }
          this.emitValue();
        },

        clearLocation: function () {
          this.lat  = '';
          this.lon  = '';
          this.zoom = 13;
          if (this.mapMarker) {
            this.mapMarker.remove();
            this.mapMarker = null;
          }
          this.$emit('input', null);
        },

        emitValue: function () {
          var lat = parseFloat(this.lat);
          var lon = parseFloat(this.lon);
          if (isNaN(lat) || isNaN(lon)) {
            this.$emit('input', null);
            return;
          }
          this.$emit('input', { lat: lat, lon: lon, zoom: this.zoom || 13 });
        }
      },

      template: `
        <div>

          <!-- Coordinates display + clear -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;font-size:0.85em;color:var(--color-text-dimmed);">
            <span>{{ displayCoords }}</span>
            <k-button v-if="hasCoords" icon="cancel" size="xs" title="Clear location" @click="clearLocation" />
          </div>

          <!-- Geocode search -->
          <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.25rem;">
            <div class="k-input" style="flex:1;display:flex;align-items:center;padding:0 0.5rem;">
              <k-icon type="search" style="flex-shrink:0;margin-right:0.4rem;" />
              <input
                type="text"
                placeholder="Search for a location…"
                :value="geocodeQuery"
                @input="geocodeQuery = $event.target.value"
                @keydown.enter.prevent="geocode"
                style="flex:1;border:none;background:transparent;outline:none;padding:0.425em 0;font:inherit;"
              />
              <k-icon v-if="isGeocoding" type="loader" style="flex-shrink:0;" />
            </div>
            <k-button
              icon="search"
              size="sm"
              :disabled="isGeocoding || !geocodeQuery"
              @click="geocode"
            />
          </div>

          <!-- Geocode results -->
          <ul v-if="geocodeResults.length > 0" style="list-style:none;margin:0 0 0.5rem;padding:0;border:1px solid #cbd5e1;border-radius:4px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.12);overflow:hidden;">
            <li
              v-for="(result, index) in geocodeResults"
              :key="result.lat + result.lon"
              :style="{padding:'0.6rem 0.75rem',cursor:'pointer',borderBottom:index<geocodeResults.length-1?'1px solid #e2e8f0':'none',background:hoveredGeoIndex===index?'#f1f5f9':'#fff'}"
              @click="selectGeocode(result)"
              @mouseover="hoveredGeoIndex = index"
              @mouseleave="hoveredGeoIndex = -1"
            >
              {{ result.displayName }}
              <small style="opacity:0.6;margin-left:0.5rem;font-size:0.8em">{{ result.lat }}, {{ result.lon }}</small>
            </li>
          </ul>
          <p v-if="geocodeError" style="color:var(--color-negative);font-size:0.85em;margin:0 0 0.5rem">{{ geocodeError }}</p>

          <!-- Map -->
          <div :id="mapId" style="height:300px;border-radius:4px;border:1px solid #cbd5e1;overflow:hidden;"></div>
          <p style="font-size:0.75em;color:#64748b;margin-top:0.25rem">Click the map or drag the marker to set the exact position</p>

        </div>
      `
    }
  },

  sections: {
    filearchivelinks: {
      data: function () {
        return {
          headline: null,
          emptyText: 'No pages link to this file.',
          indexReady: true,
          links: []
        }
      },
      created: async function () {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.emptyText = response.emptyText || this.emptyText;
          this.indexReady = response.indexReady;
          this.links = response.links || [];
        } catch (error) {
          console.error('Failed to load file archive links:', error);
        }
      },
      template: `
        <section class="k-section k-filearchivelinks-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <k-empty v-if="!indexReady" icon="search">
            File-link index not yet built. An administrator can build it from the Indexes panel.
          </k-empty>

          <k-empty v-else-if="links.length === 0" icon="check">
            {{ emptyText }}
          </k-empty>

          <ul v-else class="k-filearchivelinks-list" style="list-style: none; padding: 0; margin: 0.5rem 0 0;">
            <li
              v-for="link in links"
              :key="link.pageId"
              style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--color-border);"
            >
              <k-link
                :to="link.panelUrl"
                style="font-weight: 500; flex: 1;"
              >{{ link.title }}</k-link>
              <a
                v-if="link.url"
                :href="link.url"
                target="_blank"
                rel="noopener"
                style="color: var(--color-text-dimmed); font-size: 0.8rem;"
              >view</a>
              <span
                v-if="link.linkTypes && link.linkTypes.indexOf('permanent_url') !== -1"
                style="font-size: 0.7rem; color: var(--color-text-dimmed); border: 1px solid var(--color-border); border-radius: var(--rounded); padding: 0.05rem 0.35rem;"
                title="Linked via a hard-coded permanent URL"
              >permanent URL</span>
              <span
                v-if="link.linkTypes && link.linkTypes.indexOf('file_link') !== -1"
                style="font-size: 0.7rem; color: var(--color-text-dimmed); border: 1px solid var(--color-border); border-radius: var(--rounded); padding: 0.05rem 0.35rem;"
                title="Wrapped by a file-link page that gives this file a permanent URL"
              >file link</span>
            </li>
          </ul>
        </section>
      `
    },
    quicklinks: {
      data: function () {
        return {
          headline: null, // Stores the 'headline' prop from PHP
          links: [],      // Stores the 'links' array from PHP
        }
      },
      created: async function() {
        // The load() method fetches all data returned by the PHP file
        try {
          const response = await this.load(); // Fetches data from PHP endpoint

          this.headline = response.headline;
          this.links = response.links; // Assigns the Array(links)
        } catch (error) {
          console.error("Failed to load quicklinks section:", error);
        }
      },
      template: `
        <section class="k-section k-quicklinks-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <k-list v-if="links.length > 0">
            <k-list-item
              v-for="link in links"
              :key="link.url"
              :text="link.text"
              :info="link.info"
              :link="link.url"
              :target="'_self'"
            />
          </k-list>

          <k-empty
            v-else
            icon="link"
          >
            No quick links have been configured yet.
          </k-empty>

        </section>
      `
    },

    formsubmissionsindex: {
      data: function () {
        return {
          headline: 'Form Submissions',
          formTypes: [],
          totalCount: 0,
          exportAllUrl: ''
        }
      },
      created: async function() {
        try {
          const response = await this.load();
          this.headline     = response.headline;
          this.formTypes    = response.formTypes    || [];
          this.totalCount   = response.totalCount   || 0;
          this.exportAllUrl = response.exportAllUrl || '';
        } catch (error) {
          console.error("Failed to load form submissions index section:", error);
        }
      },
      template: `
        <section class="k-section k-formsubmissionsindex-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div v-if="formTypes.length > 0" style="padding: 0.75rem 0 0.5rem;">
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
              <thead>
                <tr style="border-bottom: 2px solid var(--color-border);">
                  <th style="text-align: left; padding: 0.5rem 0.75rem; font-size: 0.75rem; color: var(--color-text-dimmed); font-weight: 600;">Form type</th>
                  <th style="text-align: right; padding: 0.5rem 0.75rem; font-size: 0.75rem; color: var(--color-text-dimmed); font-weight: 600;">Submissions</th>
                  <th style="text-align: right; padding: 0.5rem 0.75rem; font-size: 0.75rem; color: var(--color-text-dimmed); font-weight: 600;">Export</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in formTypes"
                  :key="row.formType"
                  style="border-bottom: 1px solid var(--color-border);"
                >
                  <td style="padding: 0.6rem 0.75rem; font-size: 0.875rem;">{{ row.formType }}</td>
                  <td style="text-align: right; padding: 0.6rem 0.75rem; font-size: 0.875rem; font-weight: 500;">{{ row.count }}</td>
                  <td style="text-align: right; padding: 0.6rem 0.75rem;">
                    <a
                      :href="row.exportUrl"
                      style="font-size: 0.8rem; color: var(--color-blue-500, #2563eb); text-decoration: none; white-space: nowrap;"
                    >&#8595; CSV</a>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr style="border-top: 2px solid var(--color-border);">
                  <td style="padding: 0.6rem 0.75rem; font-size: 0.875rem; font-weight: 600;">Total</td>
                  <td style="text-align: right; padding: 0.6rem 0.75rem; font-size: 0.875rem; font-weight: 600;">{{ totalCount }}</td>
                  <td style="text-align: right; padding: 0.6rem 0.75rem;">
                    <a
                      :href="exportAllUrl"
                      style="font-size: 0.8rem; color: var(--color-blue-500, #2563eb); text-decoration: none; white-space: nowrap;"
                    >&#8595; All CSV</a>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <k-empty
            v-else
            icon="file-text"
          >
            No form submissions recorded yet.
          </k-empty>
        </section>
      `
    },

    formsubmissionexport: {
      data: function () {
        return {
          headline: 'Export Submissions',
          submissionCount: 0,
          exportUrl: ''
        }
      },
      created: async function() {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.submissionCount = response.submissionCount;
          this.exportUrl = response.exportUrl;
        } catch (error) {
          console.error("Failed to load form submission export section:", error);
        }
      },
      template: `
        <section class="k-section k-formsubmissionexport-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>
          <div style="padding: 0.75rem 0 0.5rem;">
            <p style="margin-bottom: 0.75rem; color: var(--color-text-dimmed); font-size: 0.875rem;">
              {{ submissionCount }} submission{{ submissionCount !== 1 ? 's' : '' }} available for export.
            </p>
            <a
              v-if="submissionCount > 0"
              :href="exportUrl"
              style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; background: var(--color-blue-500, #2563eb); color: #fff; border-radius: var(--rounded); font-size: 0.875rem; font-weight: 500; text-decoration: none;"
            >
              &#8595; Download CSV
            </a>
            <k-empty v-else icon="file-text">
              No submissions to export yet.
            </k-empty>
          </div>
        </section>
      `
    },

    searchindexstats: {
      data: function () {
        return {
          headline: null,
          stats: { total_pages: 0, last_rebuild: null },
          rebuilding: false
        }
      },
      created: async function() {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.stats = response.stats || this.stats;
        } catch (error) {
          console.error("Failed to load search index stats section:", error);
        }
      },
      methods: {
        rebuild: async function() {
          this.rebuilding = true;
          try {
            await fetch('/search-rebuild');
            const response = await this.load();
            this.stats = response.stats || this.stats;
          } catch (error) {
            console.error("Failed to rebuild search index:", error);
          } finally {
            this.rebuilding = false;
          }
        }
      },
      template: `
        <section class="k-section k-searchindexstats-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div style="padding: 0.75rem 0 0.5rem;">
            <div style="display: flex; align-items: center; gap: 1.5rem; padding: 0.75rem 1rem; background: var(--color-background); border-radius: var(--rounded);">
              <div>
                <strong>Search</strong>
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                {{ stats.total_pages }} pages indexed
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                Last rebuilt: {{ stats.last_rebuild || 'Never' }}
              </div>
              <div style="margin-left: auto;">
                <button
                  @click="rebuild"
                  :disabled="rebuilding"
                  style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.75rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.8rem;"
                >
                  <span v-if="rebuilding" style="display: inline-block; width: 0.8rem; height: 0.8rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span>
                  {{ rebuilding ? 'Rebuilding...' : 'Rebuild' }}
                </button>
              </div>
            </div>
          </div>

          <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
        </section>
      `
    },

    contentindexstats: {
      data: function () {
        return {
          headline: null,
          indexes: [],
          rebuildingIndex: null
        }
      },
      created: async function() {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.indexes = response.indexes || [];
        } catch (error) {
          console.error("Failed to load content index stats section:", error);
        }
      },
      methods: {
        rebuild: async function(indexName) {
          this.rebuildingIndex = indexName;
          try {
            await fetch('/content-index-rebuild?name=' + encodeURIComponent(indexName));
            const response = await this.load();
            this.indexes = response.indexes || [];
          } catch (error) {
            console.error("Failed to rebuild content index:", error);
          } finally {
            this.rebuildingIndex = null;
          }
        }
      },
      template: `
        <section class="k-section k-contentindexstats-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div v-if="indexes.length > 0" style="padding: 0.75rem 0 0.5rem;">
            <div v-for="idx in indexes" :key="idx.name" style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 0.75rem; padding: 0.75rem 1rem; background: var(--color-background); border-radius: var(--rounded);">
              <div>
                <strong style="text-transform: capitalize;">{{ idx.name }}</strong>
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                {{ idx.total_rows }} pages indexed
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                Last rebuilt: {{ idx.last_rebuild || 'Never' }}
              </div>
              <div style="margin-left: auto;">
                <button
                  @click="rebuild(idx.name)"
                  :disabled="rebuildingIndex === idx.name"
                  style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.75rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.8rem;"
                >
                  <span v-if="rebuildingIndex === idx.name" style="display: inline-block; width: 0.8rem; height: 0.8rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span>
                  {{ rebuildingIndex === idx.name ? 'Rebuilding...' : 'Rebuild' }}
                </button>
              </div>
            </div>
          </div>

          <k-empty
            v-else
            icon="database"
          >
            No content indexes registered.
          </k-empty>

        </section>
      `
    },

    searchanalytics: {
      data: function () {
        return {
          headline: null,
          topTerms: [],
          topKeywords: [],
          summary: { totalSearches: 0, uniqueTerms: 0, dateRange: { from: null, to: null } }
        }
      },
      created: async function() {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.topTerms = response.topTerms || [];
          this.topKeywords = response.topKeywords || [];
          this.summary = response.summary || this.summary;
        } catch (error) {
          console.error("Failed to load search analytics section:", error);
        }
      },
      computed: {
        formattedDateRange: function() {
          if (!this.summary.dateRange.from || !this.summary.dateRange.to) {
            return 'No data';
          }
          const from = this.summary.dateRange.from.split(' ')[0];
          const to = this.summary.dateRange.to.split(' ')[0];
          return from === to ? from : `${from} to ${to}`;
        }
      },
      template: `
        <section class="k-section k-searchanalytics-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div v-if="topTerms.length > 0 || topKeywords.length > 0" class="k-searchanalytics-content">
            <!-- Summary Stats -->
            <div class="k-searchanalytics-summary" style="display: flex; gap: 2rem; margin-bottom: 1.5rem; padding: 1rem; background: var(--color-background); border-radius: var(--rounded);">
              <div>
                <strong style="font-size: 1.5rem;">{{ summary.totalSearches }}</strong>
                <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">Total Searches</div>
              </div>
              <div>
                <strong style="font-size: 1.5rem;">{{ summary.uniqueTerms }}</strong>
                <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">Unique Terms</div>
              </div>
              <div>
                <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">Date Range</div>
                <div>{{ formattedDateRange }}</div>
              </div>
            </div>

            <!-- Tables Container -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
              <!-- Top Search Terms -->
              <div>
                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--color-text-dimmed);">Top Search Terms</h3>
                <table style="width: 100%; border-collapse: collapse;">
                  <thead>
                    <tr style="border-bottom: 1px solid var(--color-border);">
                      <th style="text-align: left; padding: 0.5rem 0; font-weight: 600; font-size: 0.75rem; color: var(--color-text-dimmed);">Term</th>
                      <th style="text-align: right; padding: 0.5rem 0; font-weight: 600; font-size: 0.75rem; color: var(--color-text-dimmed);">Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, index) in topTerms" :key="'term-' + index" style="border-bottom: 1px solid var(--color-border);">
                      <td style="padding: 0.5rem 0; font-size: 0.875rem;">{{ item.term }}</td>
                      <td style="text-align: right; padding: 0.5rem 0; font-size: 0.875rem; font-weight: 500;">{{ item.count }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Top Keywords -->
              <div>
                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--color-text-dimmed);">Top Keywords</h3>
                <table style="width: 100%; border-collapse: collapse;">
                  <thead>
                    <tr style="border-bottom: 1px solid var(--color-border);">
                      <th style="text-align: left; padding: 0.5rem 0; font-weight: 600; font-size: 0.75rem; color: var(--color-text-dimmed);">Keyword</th>
                      <th style="text-align: right; padding: 0.5rem 0; font-weight: 600; font-size: 0.75rem; color: var(--color-text-dimmed);">Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, index) in topKeywords" :key="'keyword-' + index" style="border-bottom: 1px solid var(--color-border);">
                      <td style="padding: 0.5rem 0; font-size: 0.875rem;">{{ item.keyword }}</td>
                      <td style="text-align: right; padding: 0.5rem 0; font-size: 0.875rem; font-weight: 500;">{{ item.count }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <k-empty
            v-else
            icon="search"
          >
            No search data available yet.
          </k-empty>

        </section>
      `
    },

    filteredpages: {
      data: function () {
        return {
          // Config loaded from PHP section
          headline:      '',
          modelId:       '',
          filterDefs:    {},
          columnDefs:    [],
          pageSize:      25,
          searchEnabled: false,
          defaultSort:   'title asc',
          sortOptions:   [],
          template:      '',

          // Toolbar state (persisted in localStorage)
          active:      {},
          search:      '',
          currentSort: 'title asc',
          currentPage: 1,

          // Dropdown options fetched from API
          options: {},

          // Results
          items:      [],
          total:      0,
          totalPages: 0,
          loading:    false,

          _searchTimer: null
        };
      },

      created: async function () {
        try {
          var response = await this.load();
          this.headline      = response.headline      || '';
          this.modelId       = response.modelId       || '';
          this.filterDefs    = response.filters       || {};
          this.columnDefs    = response.columns       || [];
          this.pageSize      = response.pageSize      || 25;
          this.searchEnabled = response.search        || false;
          this.defaultSort   = response.sortBy        || 'title asc';
          this.sortOptions   = response.sortOptions   || [];
          this.template      = response.template      || '';
          this.currentSort   = response.sortBy        || 'title asc';

          // Initialise every filter field to '' so each select shows "Label: All"
          var initialActive = {};
          Object.keys(this.filterDefs).forEach(function (field) { initialActive[field] = ''; });
          this.active = initialActive;

          // Then overlay any persisted selections
          var saved = this.loadSavedState();
          if (saved.active)  Object.assign(this.active, saved.active);
          if (saved.search)  this.search      = saved.search;
          if (saved.sort)    this.currentSort = saved.sort;
          if (saved.page)    this.currentPage = saved.page;

          await Promise.all([this.loadOptions(), this.loadResults()]);
        } catch (error) {
          console.error('Failed to initialise filteredpages section:', error);
        }
      },

      computed: {
        filterFields: function () {
          return Object.keys(this.filterDefs);
        },
        sortField: function () {
          return this.currentSort.split(' ')[0] || 'title';
        },
        sortDir: function () {
          return (this.currentSort.split(' ')[1] || 'asc');
        },
        hasActiveFilters: function () {
          var self = this;
          return Object.keys(self.active).some(function (k) { return self.active[k] !== ''; });
        }
      },

      methods: {
        loadSavedState: function () {
          try {
            return JSON.parse(localStorage.getItem('bsbi-filteredpages-' + this.modelId) || '{}');
          } catch (e) {
            return {};
          }
        },

        saveState: function () {
          try {
            localStorage.setItem('bsbi-filteredpages-' + this.modelId, JSON.stringify({
              active:  this.active,
              search:  this.search,
              sort:    this.currentSort,
              page:    this.currentPage
            }));
          } catch (e) { /* storage unavailable */ }
        },

        loadOptions: async function () {
          try {
            this.options = await this.$api.get('filtered-pages/options', {
              model_id: this.modelId,
              template: this.template,
              filters:  JSON.stringify(this.filterDefs)
            });
          } catch (e) {
            console.error('Failed to load filter options:', e);
          }
        },

        loadResults: async function () {
          if (!this.modelId) return;
          this.loading = true;
          try {
            var result = await this.$api.get('filtered-pages/results', {
              model_id:  this.modelId,
              template:  this.template,
              filters:   JSON.stringify(this.filterDefs),
              columns:   JSON.stringify(this.columnDefs),
              active:    JSON.stringify(this.active),
              search:    this.search,
              sort:      this.currentSort,
              page:      this.currentPage,
              page_size: this.pageSize
            });
            this.items      = result.items      || [];
            this.total      = result.total      || 0;
            this.totalPages = result.totalPages || 0;
            this.saveState();
          } catch (e) {
            console.error('Failed to load filtered pages results:', e);
          } finally {
            this.loading = false;
          }
        },

        onFilterChange: function () {
          this.currentPage = 1;
          this.loadResults();
        },

        onSearchInput: function () {
          var self = this;
          clearTimeout(self._searchTimer);
          self._searchTimer = setTimeout(function () {
            self.currentPage = 1;
            self.loadResults();
          }, 300);
        },

        goToPage: function (page) {
          if (page < 1 || page > this.totalPages) return;
          this.currentPage = page;
          this.loadResults();
        },

        changeSort: function (field) {
          var parts = this.currentSort.split(' ');
          if (parts[0] === field) {
            this.currentSort = field + ' ' + (parts[1] === 'asc' ? 'desc' : 'asc');
          } else {
            this.currentSort = field + ' asc';
          }
          this.currentPage = 1;
          this.loadResults();
        },

        sortIcon: function (field) {
          var parts = this.currentSort.split(' ');
          if (parts[0] !== field) return '\u2195';
          return parts[1] === 'asc' ? '\u2191' : '\u2193';
        },

        isSortable: function (field) {
          return this.sortOptions.some(function (opt) { return opt.field === field; });
        },

        colWidth: function (width) {
          if (!width) return 'auto';
          var parts = String(width).split('/');
          if (parts.length === 2) {
            return (parseInt(parts[0]) / parseInt(parts[1]) * 100).toFixed(2) + '%';
          }
          return width;
        },

        colFlex: function (width) {
          if (!width) return '1 1 0';
          var parts = String(width).split('/');
          if (parts.length === 2) {
            return '0 0 ' + (parseInt(parts[0]) / parseInt(parts[1]) * 100).toFixed(2) + '%';
          }
          return '0 0 ' + width;
        },

        resetFilters: function () {
          this.active      = {};
          this.search      = '';
          this.currentPage = 1;
          this.loadResults();
        },

        navigateTo: function (url) {
          window.location.href = url;
        },

        displayValue: function (item, col) {
          if (col.field === 'title')  return item.title;
          if (col.field === 'status') return item.status;
          return (item.displayValues && item.displayValues[col.field]) || '';
        },

        isExcludeFilter: function (field) {
          return (this.filterDefs[field] && this.filterDefs[field].mode === 'exclude');
        },

        filterDefaultLabel: function (field) {
          var label = this.filterDefs[field] ? this.filterDefs[field].label : field;
          return this.isExcludeFilter(field) ? 'Exclude ' + label + ': None' : label + ': All';
        }
      },

      template: `
        <section class="k-section k-filteredpages-section">

          <style>
            .k-filteredpages-section .k-fp-item:hover { background: var(--color-gray-200) !important; }
          </style>

          <!-- Header -->
          <header class="k-section-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
            <h2 class="k-headline">{{ headline }}</h2>
            <k-button
              v-if="modelId"
              :dialog="'pages/create?parent=pages/' + modelId.split('/').join('+') + (template ? '&template=' + template : '')"
              icon="add"
              text="Add"
              size="sm"
              variant="filled"
            />
          </header>

          <!-- Filter bar: distinct lighter background -->
          <div style="background:var(--color-gray-100);border-radius:var(--rounded);padding:0.65rem 0.75rem;margin-bottom:0.75rem;">
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
              <input
                v-if="searchEnabled"
                type="text"
                v-model="search"
                @input="onSearchInput"
                placeholder="Search..."
                style="flex:1;min-width:160px;padding:0.4rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.875rem;background:var(--color-white);color:var(--color-text);"
              />
              <select
                v-for="field in filterFields"
                :key="field"
                v-model="active[field]"
                @change="onFilterChange"
                :style="'padding:0.4rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.875rem;background:var(--color-white);color:var(--color-text);cursor:pointer;' + (isExcludeFilter(field) && active[field] ? 'border-color:var(--color-negative,#c82829);' : '')"
              >
                <option value="">{{ filterDefaultLabel(field) }}</option>
                <option v-for="opt in (options[field] || [])" :key="opt.value" :value="opt.value">{{ opt.text }}</option>
              </select>
              <button
                v-if="hasActiveFilters || search"
                @click="resetFilters"
                style="padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:var(--color-white);color:var(--color-text-dimmed);cursor:pointer;"
              >Clear</button>
            </div>
          </div>

          <!-- Loading -->
          <div v-if="loading" style="padding:1rem 0;color:var(--color-text-dimmed);font-size:0.875rem;">Loading&hellip;</div>

          <!-- Results list -->
          <template v-else-if="items.length > 0">

            <!-- Column header row -->
            <div v-if="columnDefs.length > 1 || sortOptions.length > 0" style="display:flex;align-items:center;padding:0.25rem 0.75rem;margin-bottom:0.15rem;">
              <div
                v-for="col in columnDefs"
                :key="col.field"
                :style="'flex:' + colFlex(col.width) + ';min-width:0;font-size:0.7rem;font-weight:600;color:var(--color-text-dimmed);text-transform:uppercase;letter-spacing:0.05em;padding:0 0;' + (isSortable(col.field) ? 'cursor:pointer;user-select:none;' : '')"
                @click="isSortable(col.field) && changeSort(col.field)"
              >
                {{ col.label }}<span v-if="isSortable(col.field)" style="opacity:0.5;margin-left:0.2rem;">{{ sortIcon(col.field) }}</span>
              </div>
            </div>

            <!-- Item cards -->
            <div style="display:flex;flex-direction:column;gap:0.25rem;">
              <div
                v-for="item in items"
                :key="item.id"
                class="k-fp-item"
                style="display:flex;align-items:center;background:var(--color-white);border-radius:var(--rounded);overflow:hidden;cursor:pointer;"
                @click="navigateTo(item.panelUrl)"
              >
                <div
                  v-for="(col, idx) in columnDefs"
                  :key="col.field"
                  :style="'flex:' + colFlex(col.width) + ';min-width:0;display:flex;align-items:center;padding:0.75rem;font-size:0.875rem;overflow:hidden;'"
                >
                  <a v-if="idx === 0" :href="item.panelUrl" @click.stop
                     style="color:var(--color-text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ displayValue(item, col) }}</a>
                  <span v-else style="color:var(--color-text-dimmed);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ displayValue(item, col) }}</span>
                </div>
              </div>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" style="display:flex;align-items:center;gap:1rem;padding:0.75rem 0;font-size:0.875rem;">
              <button @click="goToPage(currentPage - 1)" :disabled="currentPage <= 1"
                      :style="'padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:transparent;cursor:pointer;opacity:' + (currentPage <= 1 ? '0.4' : '1') + ';'"
              >&larr; Prev</button>
              <span style="color:var(--color-text-dimmed);">Page {{ currentPage }} of {{ totalPages }} &middot; {{ total }} total</span>
              <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= totalPages"
                      :style="'padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:transparent;cursor:pointer;opacity:' + (currentPage >= totalPages ? '0.4' : '1') + ';'"
              >Next &rarr;</button>
            </div>
          </template>

          <!-- Empty state -->
          <k-empty v-else icon="page">
            No pages found{{ hasActiveFilters || search ? ' matching the current filters' : '' }}.
          </k-empty>

        </section>
      `
    },

    filteredfiles: {
      data: function () {
        return {
          headline:      '',
          modelId:       '',
          apiEndpoint:   'filtered-files',
          filterDefs:    {},
          columnDefs:    [],
          pageSize:      25,
          searchEnabled: false,
          defaultSort:   'filename asc',
          sortOptions:   [],
          uploadTemplate: 'image',

          active:      {},
          search:      '',
          currentSort: 'filename asc',
          currentPage: 1,

          options: {},

          items:      [],
          total:      0,
          totalPages: 0,
          loading:    false,

          _searchTimer: null
        };
      },

      created: async function () {
        try {
          var response = await this.load();
          this.headline      = response.headline             || '';
          this.modelId       = response.modelId             || '';
          this.apiEndpoint    = response.resolvedApiEndpoint  || 'filtered-files';
          this.uploadTemplate = response.uploadTemplate      || 'image';
          this.filterDefs    = response.filters             || {};
          this.columnDefs    = response.columns             || [];
          this.pageSize      = response.pageSize            || 25;
          this.searchEnabled = response.search              || false;
          this.defaultSort   = response.sortBy              || 'filename asc';
          this.sortOptions   = response.sortOptions         || [];
          this.currentSort   = response.sortBy              || 'filename asc';

          var initialActive = {};
          Object.keys(this.filterDefs).forEach(function (field) { initialActive[field] = ''; });
          this.active = initialActive;

          var saved = this.loadSavedState();
          if (saved.active)  Object.assign(this.active, saved.active);
          if (saved.search)  this.search      = saved.search;
          if (saved.sort)    this.currentSort = saved.sort;
          if (saved.page)    this.currentPage = saved.page;

          await Promise.all([this.loadOptions(), this.loadResults()]);
        } catch (error) {
          console.error('Failed to initialise filteredfiles section:', error);
        }
      },

      computed: {
        filterFields: function () {
          return Object.keys(this.filterDefs);
        },
        sortField: function () {
          return this.currentSort.split(' ')[0] || 'filename';
        },
        sortDir: function () {
          return (this.currentSort.split(' ')[1] || 'asc');
        },
        hasActiveFilters: function () {
          var self = this;
          return Object.keys(self.active).some(function (k) { return self.active[k] !== ''; });
        }
      },

      methods: {
        loadSavedState: function () {
          try {
            return JSON.parse(localStorage.getItem('bsbi-filteredfiles-' + this.modelId) || '{}');
          } catch (e) {
            return {};
          }
        },

        saveState: function () {
          try {
            localStorage.setItem('bsbi-filteredfiles-' + this.modelId, JSON.stringify({
              active:  this.active,
              search:  this.search,
              sort:    this.currentSort,
              page:    this.currentPage
            }));
          } catch (e) { /* storage unavailable */ }
        },

        loadOptions: async function () {
          try {
            this.options = await this.$api.get(this.apiEndpoint + '/options', {
              model_id: this.modelId,
              filters:  JSON.stringify(this.filterDefs)
            });
          } catch (e) {
            console.error('Failed to load filter options:', e);
          }
        },

        loadResults: async function () {
          if (!this.modelId) return;
          this.loading = true;
          try {
            var result = await this.$api.get(this.apiEndpoint + '/results', {
              model_id:  this.modelId,
              filters:   JSON.stringify(this.filterDefs),
              columns:   JSON.stringify(this.columnDefs),
              active:    JSON.stringify(this.active),
              search:    this.search,
              sort:      this.currentSort,
              page:      this.currentPage,
              page_size: this.pageSize
            });
            this.items      = result.items      || [];
            this.total      = result.total      || 0;
            this.totalPages = result.totalPages || 0;
            this.saveState();
          } catch (e) {
            console.error('Failed to load filtered files results:', e);
          } finally {
            this.loading = false;
          }
        },

        onFilterChange: function () {
          this.currentPage = 1;
          this.loadResults();
        },

        onSearchInput: function () {
          var self = this;
          clearTimeout(self._searchTimer);
          self._searchTimer = setTimeout(function () {
            self.currentPage = 1;
            self.loadResults();
          }, 300);
        },

        goToPage: function (page) {
          if (page < 1 || page > this.totalPages) return;
          this.currentPage = page;
          this.loadResults();
        },

        changeSort: function (field) {
          var parts = this.currentSort.split(' ');
          if (parts[0] === field) {
            this.currentSort = field + ' ' + (parts[1] === 'asc' ? 'desc' : 'asc');
          } else {
            this.currentSort = field + ' asc';
          }
          this.currentPage = 1;
          this.loadResults();
        },

        sortIcon: function (field) {
          var parts = this.currentSort.split(' ');
          if (parts[0] !== field) return '\u2195';
          return parts[1] === 'asc' ? '\u2191' : '\u2193';
        },

        isSortable: function (field) {
          return this.sortOptions.some(function (opt) { return opt.field === field; });
        },

        colWidth: function (width) {
          if (!width) return 'auto';
          var parts = String(width).split('/');
          if (parts.length === 2) {
            return (parseInt(parts[0]) / parseInt(parts[1]) * 100).toFixed(2) + '%';
          }
          return width;
        },

        colFlex: function (width) {
          if (!width) return '1 1 0';
          var parts = String(width).split('/');
          if (parts.length === 2) {
            return '0 0 ' + (parseInt(parts[0]) / parseInt(parts[1]) * 100).toFixed(2) + '%';
          }
          return '0 0 ' + width;
        },

        resetFilters: function () {
          var initialActive = {};
          Object.keys(this.filterDefs).forEach(function (field) { initialActive[field] = ''; });
          this.active      = initialActive;
          this.search      = '';
          this.currentPage = 1;
          this.loadResults();
        },

        navigateTo: function (url) {
          window.location.href = url;
        },

        displayValue: function (item, col) {
          if (col.field === 'filename') return item.filename;
          if (col.field === 'title')    return item.title;
          return (item.displayValues && item.displayValues[col.field]) || '';
        },

        isExcludeFilter: function (field) {
          return (this.filterDefs[field] && this.filterDefs[field].mode === 'exclude');
        },

        filterDefaultLabel: function (field) {
          var label = this.filterDefs[field] ? this.filterDefs[field].label : field;
          return this.isExcludeFilter(field) ? 'Exclude ' + label + ': None' : label + ': All';
        },

        onDialogSuccess: function () {
          this.loadResults();
          this.loadOptions();
        },

        triggerUpload: function () {
          var self = this;
          var uploadUrl = this.$panel.urls.api + '/pages/' + this.modelId.split('/').join('+') + '/files';

          var onError = function (file) {
            self.$panel.notification.error(file.error || 'Upload failed');
            self.$panel.events.off('file.upload.error', onError);
          };
          this.$panel.events.on('file.upload.error', onError);

          this.$panel.upload.pick({
            url: uploadUrl,
            accept: this.uploadTemplate === 'image' ? 'image/*' : '*',
            attributes: { template: this.uploadTemplate },
            multiple: false,
            on: {
              done: function (files) {
                self.$panel.events.off('file.upload.error', onError);
                if (files && files.length > 0 && files[0].link) {
                  var panelBase = (document.querySelector('base')?.href || '').replace(/\/$/, '');
                  window.location.href = panelBase + files[0].link;
                } else {
                  self.loadResults();
                  self.loadOptions();
                }
              }
            }
          });
        }
      },

      template: `
        <section class="k-section k-filteredfiles-section">

          <style>
            .k-filteredfiles-section .k-ff-item:hover { background: var(--color-gray-200) !important; }
          </style>

          <!-- Header -->
          <header class="k-section-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
            <h2 class="k-headline">{{ headline }}</h2>
            <k-button
              v-if="modelId"
              icon="upload"
              text="Add"
              size="sm"
              variant="filled"
              @click="triggerUpload"
            />
          </header>

          <!-- Filter bar: distinct lighter background to stand out from results -->
          <div style="background:var(--color-gray-100);border-radius:var(--rounded);padding:0.65rem 0.75rem;margin-bottom:0.75rem;">
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
              <input
                v-if="searchEnabled"
                type="text"
                v-model="search"
                @input="onSearchInput"
                placeholder="Search..."
                style="flex:1;min-width:160px;padding:0.4rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.875rem;background:var(--color-white);color:var(--color-text);"
              />
              <select
                v-for="field in filterFields"
                :key="field"
                v-model="active[field]"
                @change="onFilterChange"
                :style="'padding:0.4rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.875rem;background:var(--color-white);color:var(--color-text);cursor:pointer;' + (isExcludeFilter(field) && active[field] ? 'border-color:var(--color-negative,#c82829);' : '')"
              >
                <option value="">{{ filterDefaultLabel(field) }}</option>
                <option v-for="opt in (options[field] || [])" :key="opt.value" :value="opt.value">{{ opt.text }}</option>
              </select>
              <button
                v-if="hasActiveFilters || search"
                @click="resetFilters"
                style="padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:var(--color-white);color:var(--color-text-dimmed);cursor:pointer;"
              >Clear</button>
            </div>
          </div>

          <!-- Loading -->
          <div v-if="loading" style="padding:1rem 0;color:var(--color-text-dimmed);font-size:0.875rem;">Loading&hellip;</div>

          <!-- Results list -->
          <template v-else-if="items.length > 0">

            <!-- Column header row (only shown when there are sortable columns or multiple columns) -->
            <div v-if="columnDefs.length > 1 || sortOptions.length > 0" style="display:flex;align-items:center;padding:0.25rem 0.75rem 0.25rem 0;margin-bottom:0.15rem;">
              <div style="width:64px;flex-shrink:0;"></div>
              <div
                v-for="col in columnDefs"
                :key="col.field"
                :style="'flex:' + colFlex(col.width) + ';min-width:0;font-size:0.7rem;font-weight:600;color:var(--color-text-dimmed);text-transform:uppercase;letter-spacing:0.05em;padding:0 0.5rem;' + (isSortable(col.field) ? 'cursor:pointer;user-select:none;' : '')"
                @click="isSortable(col.field) && changeSort(col.field)"
              >
                {{ col.label }}<span v-if="isSortable(col.field)" style="opacity:0.5;margin-left:0.2rem;">{{ sortIcon(col.field) }}</span>
              </div>
            </div>

            <!-- Item cards -->
            <div style="display:flex;flex-direction:column;gap:0.25rem;">
              <div
                v-for="item in items"
                :key="item.id"
                class="k-ff-item"
                style="display:flex;align-items:stretch;background:var(--color-white);border-radius:var(--rounded);overflow:hidden;cursor:pointer;"
                @click="navigateTo(item.panelUrl)"
              >
                <!-- Thumbnail: flush left, full row height -->
                <div style="width:64px;flex-shrink:0;overflow:hidden;">
                  <div
                    :style="'width:64px;height:100%;min-height:56px;background-color:var(--color-gray-200);' + (item.thumbUrl ? 'background-image:url(' + JSON.stringify(item.thumbUrl) + ');background-size:cover;background-position:center;' : '')"
                  ></div>
                </div>
                <!-- Column cells -->
                <div
                  v-for="(col, idx) in columnDefs"
                  :key="col.field"
                  :style="'flex:' + colFlex(col.width) + ';min-width:0;display:flex;align-items:center;padding:0.75rem 0.75rem;font-size:0.875rem;overflow:hidden;'"
                >
                  <a v-if="idx === 0" :href="item.panelUrl" @click.stop
                     style="color:var(--color-text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ displayValue(item, col) }}</a>
                  <span v-else style="color:var(--color-text-dimmed);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ displayValue(item, col) }}</span>
                </div>
              </div>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" style="display:flex;align-items:center;gap:1rem;padding:0.75rem 0;font-size:0.875rem;">
              <button @click="goToPage(currentPage - 1)" :disabled="currentPage <= 1"
                      :style="'padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:transparent;cursor:pointer;opacity:' + (currentPage <= 1 ? '0.4' : '1') + ';'"
              >&larr; Prev</button>
              <span style="color:var(--color-text-dimmed);">Page {{ currentPage }} of {{ totalPages }} &middot; {{ total }} total</span>
              <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= totalPages"
                      :style="'padding:0.35rem 0.65rem;border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.8rem;background:transparent;cursor:pointer;opacity:' + (currentPage >= totalPages ? '0.4' : '1') + ';'"
              >Next &rarr;</button>
            </div>
          </template>

          <!-- Empty state -->
          <k-empty v-else icon="image">
            No images found{{ hasActiveFilters || search ? ' matching the current filters' : '' }}.
          </k-empty>

        </section>
      `
    },

    translatedpages: {
      data: function () {
        return {
          translations: []
        }
      },
      created: async function () {
        try {
          const response = await this.load();
          this.translations = response.translations;
        } catch (error) {
          console.error("Failed to load translated pages section:", error);
        }
      },
      template: `
        <div v-if="translations.length > 0" style="margin-bottom: 1.5rem;">
          <section class="k-section k-translated-pages-section">
            <header class="k-section-header">
              <h2 class="k-headline">Translated versions</h2>
            </header>
            <div class="k-items" data-layout="list">
              <k-item
                v-for="t in translations"
                :key="t.code"
                :text="t.name"
                :info="t.code"
                :link="t.panelUrl"
                layout="list"
                :image="false"
              />
            </div>
          </section>
        </div>
      `
    },

    imagebankindexstats: {
      data: function () {
        return {
          headline: null,
          stats: null,
          rebuilding: false
        }
      },
      created: async function () {
        try {
          const response = await this.load();
          this.headline = response.headline;
          this.stats = response.stats;
        } catch (error) {
          console.error('Failed to load imageBank index stats:', error);
        }
      },
      methods: {
        rebuild: async function () {
          this.rebuilding = true;
          try {
            await fetch('/imagebank-index-rebuild');
            const response = await this.load();
            this.stats = response.stats;
          } catch (error) {
            console.error('Failed to rebuild imageBank index:', error);
          } finally {
            this.rebuilding = false;
          }
        }
      },
      template: `
        <section class="k-section k-imagebankindexstats-section">
          <header class="k-section-header">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div v-if="stats" style="padding: 0.75rem 0 0.5rem;">
            <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 0.75rem; padding: 0.75rem 1rem; background: var(--color-background); border-radius: var(--rounded);">
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                {{ stats.total_files }} files indexed
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                {{ stats.total_taxa }} taxa linked
              </div>
              <div style="color: var(--color-text-dimmed); font-size: 0.875rem;">
                Last rebuilt: {{ stats.last_rebuild || 'Never' }}
              </div>
              <div style="margin-left: auto;">
                <button
                  @click="rebuild"
                  :disabled="rebuilding"
                  style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.75rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.8rem;"
                >
                  <span v-if="rebuilding" style="display: inline-block; width: 0.8rem; height: 0.8rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span>
                  {{ rebuilding ? 'Rebuilding...' : 'Rebuild' }}
                </button>
              </div>
            </div>
          </div>

          <k-empty v-else icon="image">
            Image bank index not yet built. Click Rebuild to create it.
            <button
              @click="rebuild"
              :disabled="rebuilding"
              style="display: inline-flex; align-items: center; gap: 0.3rem; margin-top: 0.75rem; padding: 0.4rem 0.75rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.8rem;"
            >
              <span v-if="rebuilding" style="display: inline-block; width: 0.8rem; height: 0.8rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span>
              {{ rebuilding ? 'Rebuilding...' : 'Rebuild' }}
            </button>
          </k-empty>

          <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
        </section>
      `
    },

    styleguidecheck: {
      data: function () {
        return {
          headline: 'Style Guide Check',
          pageId: '',
          checking: false,
          report: '',
          error: ''
        };
      },

      created: async function () {
        try {
          var response = await this.load();
          this.headline = response.headline || this.headline;
          this.pageId   = response.pageId   || '';
        } catch (err) {
          console.error('styleguidecheck: failed to load section data', err);
        }
      },

      methods: {
        check: async function () {
          if (!this.pageId) {
            this.error = 'Could not determine the current page ID.';
            return;
          }
          this.checking = true;
          this.report   = '';
          this.error    = '';
          try {
            var result = await this.$api.post('style-guide/check', { pageId: this.pageId });
            if (result.error) {
              this.error = result.error;
            } else {
              this.report = result.report || '';
            }
          } catch (err) {
            this.error = 'Request failed: ' + (err && err.message ? err.message : String(err));
          } finally {
            this.checking = false;
          }
        },

        markdownToHtml: function (text) {
          return text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/^#### (.+)$/gm, '<h4 style="margin:0.75rem 0 0.25rem;font-size:0.85rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-dimmed)">$1</h4>')
            .replace(/^### (.+)$/gm, '<h3 style="margin:1rem 0 0.25rem;font-size:0.9rem;font-weight:700">$1</h3>')
            .replace(/^## (.+)$/gm, '<h2 style="margin:1.25rem 0 0.4rem;font-size:1rem;font-weight:700;border-bottom:1px solid var(--color-border);padding-bottom:0.25rem">$1</h2>')
            .replace(/^# (.+)$/gm, '<h1 style="margin:0 0 0.75rem;font-size:1.1rem;font-weight:700">$1</h1>')
            .replace(/^[-*] (.+)$/gm, '<li style="margin:0.15rem 0 0.15rem 1.25rem;list-style:disc">$1</li>')
            .replace(/(<li[^>]*>.*<\/li>\n?)+/g, function (m) { return '<ul style="margin:0.25rem 0">' + m + '</ul>'; })
            .replace(/\n\n+/g, '</p><p style="margin:0.5rem 0">')
            .replace(/\n/g, '<br>');
        }
      },

      template: `
        <section class="k-section k-styleguidecheck-section">
          <header class="k-section-header" style="margin-bottom:0.75rem">
            <h2 class="k-headline">{{ headline }}</h2>
          </header>

          <div style="margin-bottom:0.75rem;padding:0.5rem 0.75rem;background:var(--color-yellow-200,#fef9c3);border:1px solid var(--color-yellow-400,#facc15);border-radius:var(--rounded);font-size:0.8rem;color:var(--color-text)">
            <strong>Work in progress</strong> — this feature is experimental and results may not be fully accurate.
          </div>

          <div>
            <button
              @click="check"
              :disabled="checking"
              style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.9rem;background:var(--color-black);color:var(--color-white);border:none;cursor:pointer;border-radius:var(--rounded);font-size:0.85rem;font-weight:500"
            >
              <span v-if="checking" style="display:inline-block;width:0.75rem;height:0.75rem;border:2px solid var(--color-white);border-top-color:transparent;border-radius:50%;animation:sgc-spin 0.6s linear infinite;flex-shrink:0"></span>
              {{ checking ? 'Checking…' : 'Check using style guide' }}
            </button>
          </div>

          <div v-if="error" style="margin-top:0.75rem;padding:0.6rem 0.75rem;background:var(--color-red-200,#fee2e2);border:1px solid var(--color-red-400,#f87171);border-radius:var(--rounded);font-size:0.85rem;color:var(--color-red-800,#991b1b)">
            {{ error }}
          </div>

          <div v-if="report" style="margin-top:0.75rem;padding:0.75rem 1rem;background:var(--color-background);border:1px solid var(--color-border);border-radius:var(--rounded);font-size:0.85rem;line-height:1.6">
            <p style="margin:0.5rem 0" v-html="markdownToHtml(report)"></p>
          </div>

          <style>@keyframes sgc-spin { to { transform: rotate(360deg); } }</style>
        </section>
      `
    }
  },

  components: {
    'k-maintenance-view': {
      props: ['authorized', 'retentionDays', 'disk', 'tasks'],
      data: function () {
        return {
          currentTasks: this.tasks || [],
          currentDisk: this.disk,
          running: null,
          progress: null,
          refreshing: false,
          lastResult: null,
          deferredLoading: {}
        };
      },
      created: function () {
        // Expensive previews (media) arrive as deferred placeholders; fetch them lazily so
        // the dashboard itself renders immediately.
        this.loadDeferredPreviews();
      },
      computed: {
        diskReports: function () {
          if (!this.currentDisk) return [];
          return [
            { label: 'Disk free', value: this.currentDisk.freeHuman, icon: 'server' },
            { label: 'Disk total', value: this.currentDisk.totalHuman, icon: 'server' },
            { label: 'Used', value: this.currentDisk.usedPercent + '%', icon: 'chart' }
          ];
        },
        // Label of the task currently running, for the visually-hidden progress announcer.
        runningLabel: function () {
          var self = this;
          var task = (self.currentTasks || []).find(function (t) { return t.key === self.running; });
          return task ? task.label : 'Cleanup';
        }
      },
      methods: {
        // Re-fetch the preview counts + disk usage in place, so a run's effect shows
        // immediately without a full view reload (which would also drop keyboard focus).
        refresh: function () {
          var self = this;
          self.refreshing = true;
          return self.$api.get('maintenance/dashboard')
            .then(function (data) {
              if (data && data.tasks) {
                self.currentTasks = data.tasks;
                self.currentDisk = data.disk;
                self.loadDeferredPreviews();
              }
            })
            .catch(function (error) {
              console.error('Failed to refresh maintenance dashboard:', error);
            })
            .finally(function () {
              self.refreshing = false;
            });
        },
        // Fetch every deferred task's real preview from the time-limit-lifted endpoint.
        loadDeferredPreviews: function () {
          var self = this;
          (self.currentTasks || []).forEach(function (task) {
            if (task.deferred) self.loadPreview(task);
          });
        },
        loadPreview: function (task) {
          var self = this;
          self.$set(self.deferredLoading, task.key, true);
          self.$api.get('maintenance/preview', { key: task.key, retentionDays: self.retentionDays })
            .then(function (data) {
              // Mutate the existing (reactive) task object in place; deferred → false reveals counts.
              Object.assign(task, {
                items: data.items,
                bytes: data.bytes,
                humanBytes: data.humanBytes,
                sample: data.sample,
                error: data.error,
                countOnly: data.countOnly,
                deferred: false
              });
            })
            .catch(function (error) {
              task.error = true;
              task.deferred = false;
              console.error('Failed to load preview for ' + task.key + ':', error);
            })
            .finally(function () {
              self.$set(self.deferredLoading, task.key, false);
            });
        },
        // Format a byte count the same way the PHP MaintenanceFilesystem::humanBytes does,
        // so the accumulated client-side total reads consistently with the previews.
        humanBytes: function (bytes) {
          if (bytes < 1024) return bytes + ' B';
          if (bytes < 1048576) return (Math.round(bytes / 1024 * 10) / 10) + ' KB';
          if (bytes < 1073741824) return (Math.round(bytes / 1048576 * 10) / 10) + ' MB';
          return (Math.round(bytes / 1073741824 * 100) / 100) + ' GB';
        },
        // Run a task to completion. Blocking tasks (logs/cache/import) finish in one request
        // (done:true immediately); the chunked media task loops, feeding nextOffset back and
        // advancing a progress estimate against the previewed item count.
        runTask: async function (task) {
          var self = this;
          if (task.items === 0) return;
          // Count-only tasks (media) have no pre-computed size; the run reports the real total.
          var impact = task.countOnly
            ? 'This will clear ' + task.items + ' item(s); freed space is reported when it finishes.'
            : 'This will free about ' + task.humanBytes + ' (' + task.items + ' item(s)).';
          var msg = 'Run the "' + task.label + '" cleanup?\n\n' + impact + '\nThis cannot be undone.';
          if (!window.confirm(msg)) return;

          self.running = task.key;
          self.progress = null;
          self.lastResult = null;

          var offset = 0;
          var totalReclaimed = 0;
          var totalProcessed = 0;
          var guard = 0;

          try {
            var done = false;
            while (!done) {
              if (++guard > 100000) throw new Error('Too many chunks; aborting to avoid a loop.');
              var res = await self.$api.post('maintenance/run', {
                key: task.key,
                retentionDays: self.retentionDays,
                offset: offset
              });
              totalProcessed += res.processed;
              totalReclaimed += res.reclaimedBytes;
              offset = res.nextOffset;
              done = res.done;
              if (task.items > 0) {
                self.progress = Math.min(100, Math.round(totalProcessed / task.items * 100));
              }
            }
            self.lastResult = 'Freed ' + self.humanBytes(totalReclaimed)
              + ' from "' + task.label + '" (' + totalProcessed + ' item(s)).';
            // Refresh the previews so the just-cleaned task now reads "Nothing to reclaim".
            await self.refresh();
          } catch (error) {
            var detail = (error && error.message) ? error.message : 'The task could not be run.';
            self.lastResult = 'Error running "' + task.label + '": ' + detail;
          } finally {
            self.running = null;
            self.progress = null;
          }
        }
      },
      template: `
        <k-panel-inside class="k-maintenance-view">
          <k-header>
            Maintenance
            <template #buttons>
              <k-button
                icon="refresh"
                text="Refresh"
                size="sm"
                variant="filled"
                :disabled="refreshing || running !== null"
                @click="refresh"
              />
            </template>
          </k-header>

          <k-box
            v-if="!authorized"
            theme="negative"
            text="You must be an administrator to use the maintenance tools."
          />

          <template v-else>
            <k-text>
              Reclaim disk space by removing provably-dead data. Each task shows a dry-run
              preview first; nothing is deleted until you confirm. Retention window:
              {{ retentionDays }} days.
            </k-text>

            <k-stats
              v-if="currentDisk"
              :reports="diskReports"
              size="huge"
            />

            <div role="status" aria-live="polite" aria-atomic="true">
              <k-box
                v-if="lastResult"
                theme="info"
                :text="lastResult"
              />
            </div>

            <!-- Visually-hidden announcer for run progress; polite so it never interrupts. -->
            <div
              role="status"
              aria-live="polite"
              aria-atomic="true"
              style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"
            >
              {{ running !== null && progress !== null ? (runningLabel + ': ' + progress + ' percent complete') : '' }}
            </div>

            <div class="k-maintenance-tasks">
              <div
                v-for="task in currentTasks"
                :key="task.key"
                class="k-maintenance-task"
              >
                <div class="k-maintenance-task-info">
                  <h2>{{ task.label }}</h2>
                  <p class="k-maintenance-task-desc">{{ task.description }}</p>
                  <p class="k-maintenance-task-preview" role="status" aria-live="polite" aria-atomic="true">
                    <strong v-if="task.deferred || deferredLoading[task.key]">Calculating…</strong>
                    <strong v-else-if="task.error" style="color: var(--color-negative);">Preview failed</strong>
                    <strong v-else-if="task.items === 0">Nothing to reclaim</strong>
                    <strong v-else-if="task.countOnly">{{ task.items }} old item(s) to clear</strong>
                    <strong v-else>Would free {{ task.humanBytes }} &middot; {{ task.items }} item(s)</strong>
                  </p>
                  <ul v-if="task.sample && task.sample.length" class="k-maintenance-sample">
                    <li v-for="(line, i) in task.sample" :key="i">{{ line }}</li>
                  </ul>
                </div>
                <div class="k-maintenance-task-action">
                  <k-button
                    :icon="running === task.key ? 'loader' : 'trash'"
                    :text="running === task.key ? (progress !== null ? ('Running ' + progress + '%') : 'Running…') : 'Run'"
                    size="sm"
                    variant="filled"
                    theme="negative"
                    :aria-label="running === task.key
                      ? ('Running ' + task.label + (progress !== null ? ' ' + progress + '%' : ''))
                      : (task.deferred || deferredLoading[task.key]
                        ? ('Calculating ' + task.label + ' preview, please wait')
                        : (task.items === 0 ? ('Nothing to reclaim for ' + task.label) : ('Run ' + task.label + ' cleanup')))"
                    :disabled="task.deferred || deferredLoading[task.key] || task.items === 0 || task.error || running !== null"
                    @click="runTask(task)"
                  />
                </div>
              </div>
            </div>

            <k-empty
              v-if="currentTasks.length === 0"
              icon="trash"
            >
              No maintenance tasks are registered.
            </k-empty>
          </template>
        </k-panel-inside>
      `
    },

    'k-index-stats-view': {
      props: ['searchStats', 'contentIndexes', 'imageBankStats', 'fileLinkStats'],
      data: function () {
        return {
          currentSearchStats: this.searchStats,
          currentContentIndexes: this.contentIndexes,
          currentImageBankStats: this.imageBankStats,
          currentFileLinkStats: this.fileLinkStats,
          rebuilding: null,
          shownRecords: {},
          recordData: {},
          loadingRecords: {}
        }
      },
      methods: {
        rebuild: async function (type, name) {
          this.rebuilding = type === 'content' ? name : type;
          try {
            if (type === 'search') {
              await fetch('/search-rebuild');
              const res = await fetch('/search-stats');
              this.currentSearchStats = await res.json();
            } else if (type === 'content') {
              await fetch('/content-index-rebuild?name=' + encodeURIComponent(name));
              const res = await fetch('/content-index-stats');
              this.currentContentIndexes = await res.json();
            } else if (type === 'imagebank') {
              await fetch('/imagebank-index-rebuild');
              const res = await fetch('/imagebank-index-stats');
              this.currentImageBankStats = await res.json();
            } else if (type === 'filelinks') {
              await fetch('/filelinks-index-rebuild');
              const res = await fetch('/filelinks-index-stats');
              this.currentFileLinkStats = await res.json();
            }
          } catch (error) {
            console.error('Index rebuild failed:', error);
          } finally {
            this.rebuilding = null;
          }
        },

        toggleRecords: async function (key, type, name) {
          if (this.shownRecords[key]) {
            this.shownRecords = Object.assign({}, this.shownRecords, { [key]: false });
            this.recordData   = Object.assign({}, this.recordData,   { [key]: null });
          } else {
            await this.fetchRecords(key, type, name, 1);
            this.shownRecords = Object.assign({}, this.shownRecords, { [key]: true });
          }
        },

        fetchRecords: async function (key, type, name, page) {
          this.loadingRecords = Object.assign({}, this.loadingRecords, { [key]: true });
          try {
            var url;
            if (type === 'search') {
              url = '/search-index-records?page=' + page;
            } else if (type === 'content') {
              url = '/content-index-records?name=' + encodeURIComponent(name) + '&page=' + page;
            } else {
              url = '/imagebank-index-records?page=' + page;
            }
            var res = await fetch(url);
            if (!res.ok) {
              throw new Error('HTTP ' + res.status + ' fetching ' + url);
            }
            var data = await res.json();
            this.recordData = Object.assign({}, this.recordData, { [key]: data });
          } catch (e) {
            console.error('Failed to fetch index records:', e);
          } finally {
            this.loadingRecords = Object.assign({}, this.loadingRecords, { [key]: false });
          }
        },

        goToPage: async function (key, type, name, page) {
          await this.fetchRecords(key, type, name, page);
        }
      },
      template: `
        <k-panel-inside class="k-index-stats-view">
          <k-header>Indexes</k-header>
          <style>@keyframes k-idx-spin { to { transform: rotate(360deg); } }</style>

          <div style="display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem 0;">

            <!-- Search Index -->
            <div style="background: var(--color-background); border-radius: var(--rounded); padding: 1.25rem;">
              <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <h2 style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-dimmed); min-width: 120px; margin: 0;">Search Index</h2>
                <span v-if="currentSearchStats" style="font-size: 0.85rem; color: var(--color-text-dimmed);">{{ currentSearchStats.total_pages }} pages &middot; Last rebuilt: {{ currentSearchStats.last_rebuild || 'Never' }}</span>
                <span v-else style="font-size: 0.85rem; color: var(--color-text-dimmed);">Not yet built</span>
                <div style="margin-left: auto; display: flex; gap: 0.5rem; flex-shrink: 0;">
                  <button
                    v-if="currentSearchStats"
                    @click="toggleRecords('search', 'search', null)"
                    :disabled="loadingRecords['search']"
                    style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: transparent; color: var(--color-text); border: 1px solid var(--color-border); cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                  >
                    <span v-if="loadingRecords['search']" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                    {{ shownRecords['search'] ? 'Hide' : 'Show' }}
                  </button>
                  <button
                    @click="rebuild('search')"
                    :disabled="rebuilding !== null"
                    style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                    :style="{ opacity: rebuilding !== null && rebuilding !== 'search' ? '0.4' : '1' }"
                  >
                    <span v-if="rebuilding === 'search'" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                    {{ rebuilding === 'search' ? 'Rebuilding...' : 'Rebuild' }}
                  </button>
                </div>
              </div>
              <div v-if="shownRecords['search'] && recordData['search']" style="margin-top: 1rem; overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                  <thead>
                    <tr style="border-bottom: 2px solid var(--color-border);">
                      <th v-for="col in recordData['search'].columns" :key="col" style="text-align: left; padding: 0.4rem 0.5rem; color: var(--color-text-dimmed); font-weight: 600; white-space: nowrap;">{{ col }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in recordData['search'].rows" :key="i" style="border-bottom: 1px solid var(--color-border);">
                      <td v-for="col in recordData['search'].columns" :key="col" style="padding: 0.4rem 0.5rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col] }}</td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="recordData['search'].totalPages > 1" style="display: flex; align-items: center; gap: 1rem; padding: 0.5rem 0; font-size: 0.8rem; color: var(--color-text-dimmed);">
                  <button @click="goToPage('search', 'search', null, recordData['search'].page - 1)" :disabled="recordData['search'].page <= 1" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['search'].page <= 1 ? '0.4' : '1' }">&larr; Prev</button>
                  <span>Page {{ recordData['search'].page }} of {{ recordData['search'].totalPages }} &middot; {{ recordData['search'].total }} total</span>
                  <button @click="goToPage('search', 'search', null, recordData['search'].page + 1)" :disabled="recordData['search'].page >= recordData['search'].totalPages" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['search'].page >= recordData['search'].totalPages ? '0.4' : '1' }">Next &rarr;</button>
                </div>
              </div>
            </div>

            <!-- Content Indexes -->
            <template v-if="currentContentIndexes && currentContentIndexes.length > 0">
              <div v-for="idx in currentContentIndexes" :key="idx.name" style="background: var(--color-background); border-radius: var(--rounded); padding: 1.25rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                  <h2 style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-dimmed); min-width: 120px; margin: 0;">{{ idx.name }}</h2>
                  <span style="font-size: 0.85rem; color: var(--color-text-dimmed);">{{ idx.total_rows }} rows &middot; Last rebuilt: {{ idx.last_rebuild || 'Never' }}</span>
                  <div style="margin-left: auto; display: flex; gap: 0.5rem; flex-shrink: 0;">
                    <button
                      @click="toggleRecords('content:' + idx.name, 'content', idx.name)"
                      :disabled="loadingRecords['content:' + idx.name]"
                      style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: transparent; color: var(--color-text); border: 1px solid var(--color-border); cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                    >
                      <span v-if="loadingRecords['content:' + idx.name]" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                      {{ shownRecords['content:' + idx.name] ? 'Hide' : 'Show' }}
                    </button>
                    <button
                      @click="rebuild('content', idx.name)"
                      :disabled="rebuilding !== null"
                      style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                      :style="{ opacity: rebuilding !== null && rebuilding !== idx.name ? '0.4' : '1' }"
                    >
                      <span v-if="rebuilding === idx.name" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                      {{ rebuilding === idx.name ? 'Rebuilding...' : 'Rebuild' }}
                    </button>
                  </div>
                </div>
                <div v-if="shownRecords['content:' + idx.name] && recordData['content:' + idx.name]" style="margin-top: 1rem; overflow-x: auto;">
                  <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                    <thead>
                      <tr style="border-bottom: 2px solid var(--color-border);">
                        <th v-for="col in recordData['content:' + idx.name].columns" :key="col" style="text-align: left; padding: 0.4rem 0.5rem; color: var(--color-text-dimmed); font-weight: 600; white-space: nowrap;">{{ col }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(row, i) in recordData['content:' + idx.name].rows" :key="i" style="border-bottom: 1px solid var(--color-border);">
                        <td v-for="col in recordData['content:' + idx.name].columns" :key="col" style="padding: 0.4rem 0.5rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col] }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="recordData['content:' + idx.name].totalPages > 1" style="display: flex; align-items: center; gap: 1rem; padding: 0.5rem 0; font-size: 0.8rem; color: var(--color-text-dimmed);">
                    <button @click="goToPage('content:' + idx.name, 'content', idx.name, recordData['content:' + idx.name].page - 1)" :disabled="recordData['content:' + idx.name].page <= 1" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['content:' + idx.name].page <= 1 ? '0.4' : '1' }">&larr; Prev</button>
                    <span>Page {{ recordData['content:' + idx.name].page }} of {{ recordData['content:' + idx.name].totalPages }} &middot; {{ recordData['content:' + idx.name].total }} total</span>
                    <button @click="goToPage('content:' + idx.name, 'content', idx.name, recordData['content:' + idx.name].page + 1)" :disabled="recordData['content:' + idx.name].page >= recordData['content:' + idx.name].totalPages" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['content:' + idx.name].page >= recordData['content:' + idx.name].totalPages ? '0.4' : '1' }">Next &rarr;</button>
                  </div>
                </div>
              </div>
            </template>
            <div v-else style="background: var(--color-background); border-radius: var(--rounded); padding: 1.25rem;">
              <k-empty icon="table">No content indexes registered</k-empty>
            </div>

            <!-- Image Bank Index -->
            <div style="background: var(--color-background); border-radius: var(--rounded); padding: 1.25rem;">
              <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <h2 style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-dimmed); min-width: 120px; margin: 0;">Image Bank Index</h2>
                <span v-if="currentImageBankStats" style="font-size: 0.85rem; color: var(--color-text-dimmed);">{{ currentImageBankStats.total_files }} files &middot; {{ currentImageBankStats.total_taxa }} taxa &middot; Last rebuilt: {{ currentImageBankStats.last_rebuild || 'Never' }}</span>
                <span v-else style="font-size: 0.85rem; color: var(--color-text-dimmed);">Not yet built</span>
                <div style="margin-left: auto; display: flex; gap: 0.5rem; flex-shrink: 0;">
                  <button
                    v-if="currentImageBankStats"
                    @click="toggleRecords('imagebank', 'imagebank', null)"
                    :disabled="loadingRecords['imagebank']"
                    style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: transparent; color: var(--color-text); border: 1px solid var(--color-border); cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                  >
                    <span v-if="loadingRecords['imagebank']" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                    {{ shownRecords['imagebank'] ? 'Hide' : 'Show' }}
                  </button>
                  <button
                    @click="rebuild('imagebank')"
                    :disabled="rebuilding !== null"
                    style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                    :style="{ opacity: rebuilding !== null && rebuilding !== 'imagebank' ? '0.4' : '1' }"
                  >
                    <span v-if="rebuilding === 'imagebank'" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                    {{ rebuilding === 'imagebank' ? 'Rebuilding...' : 'Rebuild' }}
                  </button>
                </div>
              </div>
              <div v-if="shownRecords['imagebank'] && recordData['imagebank']" style="margin-top: 1rem; overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                  <thead>
                    <tr style="border-bottom: 2px solid var(--color-border);">
                      <th v-for="col in recordData['imagebank'].columns" :key="col" style="text-align: left; padding: 0.4rem 0.5rem; color: var(--color-text-dimmed); font-weight: 600; white-space: nowrap;">{{ col }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in recordData['imagebank'].rows" :key="i" style="border-bottom: 1px solid var(--color-border);">
                      <td v-for="col in recordData['imagebank'].columns" :key="col" style="padding: 0.4rem 0.5rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col] }}</td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="recordData['imagebank'].totalPages > 1" style="display: flex; align-items: center; gap: 1rem; padding: 0.5rem 0; font-size: 0.8rem; color: var(--color-text-dimmed);">
                  <button @click="goToPage('imagebank', 'imagebank', null, recordData['imagebank'].page - 1)" :disabled="recordData['imagebank'].page <= 1" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['imagebank'].page <= 1 ? '0.4' : '1' }">&larr; Prev</button>
                  <span>Page {{ recordData['imagebank'].page }} of {{ recordData['imagebank'].totalPages }} &middot; {{ recordData['imagebank'].total }} total</span>
                  <button @click="goToPage('imagebank', 'imagebank', null, recordData['imagebank'].page + 1)" :disabled="recordData['imagebank'].page >= recordData['imagebank'].totalPages" style="padding: 0.25rem 0.5rem; border: 1px solid var(--color-border); border-radius: var(--rounded); background: transparent; cursor: pointer; font-size: 0.75rem;" :style="{ opacity: recordData['imagebank'].page >= recordData['imagebank'].totalPages ? '0.4' : '1' }">Next &rarr;</button>
                </div>
              </div>
            </div>

            <!-- File-Link (reverse-link) Index -->
            <div style="background: var(--color-background); border-radius: var(--rounded); padding: 1.25rem;">
              <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <h2 style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-dimmed); min-width: 120px; margin: 0;">File Links</h2>
                <span v-if="currentFileLinkStats" style="font-size: 0.85rem; color: var(--color-text-dimmed);">{{ currentFileLinkStats.total_files }} files &middot; {{ currentFileLinkStats.total_links }} links across {{ currentFileLinkStats.total_pages }} pages &middot; Last rebuilt: {{ currentFileLinkStats.last_rebuild || 'Never' }}</span>
                <span v-else style="font-size: 0.85rem; color: var(--color-text-dimmed);">Not yet built</span>
                <div style="margin-left: auto; display: flex; gap: 0.5rem; flex-shrink: 0;">
                  <button
                    @click="rebuild('filelinks')"
                    :disabled="rebuilding !== null"
                    style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: var(--color-black); color: var(--color-white); border: none; cursor: pointer; border-radius: var(--rounded); font-size: 0.75rem;"
                    :style="{ opacity: rebuilding !== null && rebuilding !== 'filelinks' ? '0.4' : '1' }"
                  >
                    <span v-if="rebuilding === 'filelinks'" style="display: inline-block; width: 0.65rem; height: 0.65rem; border: 2px solid var(--color-white); border-top-color: transparent; border-radius: 50%; animation: k-idx-spin 0.6s linear infinite;"></span>
                    {{ rebuilding === 'filelinks' ? 'Rebuilding...' : 'Rebuild' }}
                  </button>
                </div>
              </div>
            </div>

          </div>
        </k-panel-inside>
      `
    }
  }
});
