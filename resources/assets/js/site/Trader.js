import { EventBus } from '../common/event-bus';
import Building from './Building';
import Mission from './Mission';

export default Building.extend({
    props: ['grid', 'url', 'storeUrl'],

    components: {
        Mission
    },

    data() {
        return {
            mined: 0,
            planet: {
                resources: []
            },
            data: {
                missions: []
            }
        };
    },

    created() {
        EventBus.$on('resource-updated', resource => this.mined = resource);
        EventBus.$on('planet-update', () => this.fetchData());
        EventBus.$on('planet-updated', planet => this.planet = planet);
    },

    computed: {
        isEmpty() {
            return !this.data.missions.length;
        }
    },

    watch: {
        isEnabled() {
            this.fetchData();
        }
    },

    methods: {
        fetchData() {
            if (!this.isEnabled) {
                return;
            }

            axios.get(
                this.url.replace('__grid__', this.grid.id)
            ).then(
                response => this.data = response.data
            );
        },

        isCompletable(mission) {
            let isCompletable = false;

            _.forEach(
                mission.resources, resource => isCompletable = resource.quantity <= this.resourceQuantity(resource)
            );

            return isCompletable;
        },

        resourceQuantity(resource) {
            if (this.planet.resource_id === resource.id) {
                return Math.floor(this.mined);
            }

            return _.get(_.find(this.planet.resources, {
                id: resource.id
            }), 'quantity', 0);
        },

        store(mission) {
            axios.post(
                this.storeUrl.replace('__grid__', this.grid.id).replace('__mission__', mission.id)
            );
        }
    }
});
