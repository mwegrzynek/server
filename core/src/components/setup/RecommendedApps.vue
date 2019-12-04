<!--
  - @copyright 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
  -
  - @author 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program.  If not, see <http://www.gnu.org/licenses/>.
  -->

<template>
	<div class="update">
		<h1>{{ t('core', 'Recommended apps') }}</h1>
		<div v-if="loadingApps" class="loading">
			{{ t('core', 'Loading apps …') }}
		</div>
		<div v-else>
			<div v-for="app in recommendedApps" :key="app.id" class="app">
				<img v-if="app.screenshot" :src="app.screenshot" :alt="t('core', 'screenshot of Nextcloud app {app}', { app: app.name })">
				<div v-else class="no-screenshot" />
				<div class="info">
					<h2>{{ app.name }}
						<span v-if="app.loading" class="icon icon-loading-small" />
						<span v-else-if="app.active" class="icon icon-checkmark-white" />
					</h2>
					<p>{{ app.summary }}</p>
					<p v-if="!app.canInstall" class="error">{{ t('core', 'Can\'t install this app') }}</p>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import pLimit from 'p-limit'

import logger from '../../logger'

const recommended = [
	'contacts',
	'calendar',
	'mail',
	'photos'
]

export default {
	name: 'RecommendedApps',
	data() {
		return {
			loadingApps: true,
			apps: []
		}
	},
	computed: {
		recommendedApps() {
			return this.apps.filter(app => recommended.includes(app.id))
		}
	},
	mounted() {
		return axios.get(generateUrl('settings/apps/list'))
			.then(resp => resp.data)
			.then(data => {
				logger.info(`${data.apps.length} apps fetched`)

				this.apps = data.apps.map(app => Object.assign(app, { loading: false }))
				this.loadingApps = false
				logger.debug(`${this.recommendedApps.length} recommended apps found`, { apps: this.recommendedApps })

				this.installApps()
			})
			.catch(error => {
				logger.error('could not fetch app list', { error })
			})
	},
	methods: {
		installApps() {
			const limit = pLimit(1)
			const installing = this.recommendedApps
				.filter(app => !app.active && app.canInstall)
				.map(app => limit(() => {
					app.loading = true
					axios.post(generateUrl(`settings/apps/enable`), { appIds: [app.id], groups: [] })
						.then(() => {
							// app.loading = false
						})
				}))
			logger.debug(`installing ${installing.length} recommended apps`)
			Promise.all(installing)
				.then(() => logger.info('all recommended apps installed'))
				.catch(error => logger.error('could not install recommended apps', { error }))
		}
	}
}
</script>

<style lang="scss" scoped>
div.loading {
	height: 100px;
}
.app {
	display: flex;
	flex-direction: row;

	img, .no-screenshot {
		width: 130px;
	}

	img, .no-screenshot, .info {
		padding: 12px;
	}

	.info {
		h2 {
			text-align: left;
		}

		h2 > span.icon {
			display: inline-block;
		}
	}
}
</style>
