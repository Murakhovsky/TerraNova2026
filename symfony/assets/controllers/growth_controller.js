import { Controller } from '@hotwired/stimulus';
import { bootGrowth } from '../growth/workspace.js';
export default class extends Controller { connect(){ bootGrowth(this.element); } }
