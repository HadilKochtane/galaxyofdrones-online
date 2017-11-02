import { EventBus } from '../common/event-bus';
import Modal from './Modal';

export default Modal.extend({
    created() {
        EventBus.$on('bookmark-click', this.open);
    },

    methods: {
        open() {
            this.$nextTick(() => this.$modal.modal());
        }
    }
});